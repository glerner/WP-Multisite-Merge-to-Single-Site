<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\SerializedDataRewriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SerializedDataRewriter::class )]
final class SerializedDataRewriterTest extends TestCase {

	public function testDetectsSerializedStrings(): void {
		$rewriter = new SerializedDataRewriter();

		self::assertTrue( $rewriter->isSerialized( 'a:1:{s:3:"foo";s:3:"bar";}' ) );
		self::assertTrue( $rewriter->isSerialized( 's:4:"test";' ) );
		self::assertTrue( $rewriter->isSerialized( 'i:42;' ) );
		self::assertTrue( $rewriter->isSerialized( 'b:1;' ) );
		self::assertTrue( $rewriter->isSerialized( 'b:0;' ) );
		self::assertTrue( $rewriter->isSerialized( 'O:8:"stdClass":1:{s:1:"a";i:1;}' ) );

		self::assertFalse( $rewriter->isSerialized( 'plain text' ) );
		self::assertFalse( $rewriter->isSerialized( '{"json": true}' ) );
		self::assertFalse( $rewriter->isSerialized( '' ) );
		self::assertFalse( $rewriter->isSerialized( 'a:invalid' ) );
	}

	public function testDetectsJsonStrings(): void {
		$rewriter = new SerializedDataRewriter();

		self::assertTrue( $rewriter->isJson( '{"foo": "bar"}' ) );
		self::assertTrue( $rewriter->isJson( '["a", "b", "c"]' ) );

		self::assertFalse( $rewriter->isJson( 'not json' ) );
		self::assertFalse( $rewriter->isJson( '42' ) );
		self::assertFalse( $rewriter->isJson( '' ) );
	}

	public function testRewritesStringsAndRecalculatesByteLength(): void {
		$rewriter = new SerializedDataRewriter();

		$original = serialize(
			array(
				'site_url' => 'http://short.com',
				'nested'   => array(
					'link' => 'http://short.com/page/',
				),
			)
		);

		$newUrl = 'https://very-long-canonical-destination-domain.example.com';
		$result = $rewriter->rewriteStrings(
			$original,
			static function ( string $value ) use ( $newUrl ): string {
				return str_replace( 'http://short.com', $newUrl, $value );
			}
		);

		// Assert string length prefix was updated and can be safely unserialized
		$unserialized = unserialize( $result, array( 'allowed_classes' => false ) );

		self::assertIsArray( $unserialized );
		self::assertSame( $newUrl, $unserialized['site_url'] );
		self::assertSame( $newUrl . '/page/', $unserialized['nested']['link'] );
	}

	public function testRewritesIntegers(): void {
		$rewriter = new SerializedDataRewriter();

		$original = serialize(
			array(
				'attachment_id' => 10,
				'author_id'     => 20,
				'name'          => 'test',
			)
		);

		$idMap = array(
			10 => 100,
			20 => 200,
		);

		$result = $rewriter->rewriteIntegers(
			$original,
			static function ( int $value ) use ( $idMap ): int {
				return $idMap[ $value ] ?? $value;
			}
		);

		$unserialized = unserialize( $result, array( 'allowed_classes' => false ) );

		self::assertIsArray( $unserialized );
		self::assertSame( 100, $unserialized['attachment_id'] );
		self::assertSame( 200, $unserialized['author_id'] );
		self::assertSame( 'test', $unserialized['name'] );
	}

	public function testRewritesStdClassObjects(): void {
		$rewriter = new SerializedDataRewriter();

		$obj      = new \stdClass();
		$obj->url = 'http://old.example';
		$original = serialize( $obj );

		$result = $rewriter->rewriteStrings(
			$original,
			static function ( string $value ): string {
				return str_replace( 'http://old.example', 'https://new.example', $value );
			}
		);

		$unserialized = unserialize( $result, array( 'allowed_classes' => array( 'stdClass' ) ) );

		self::assertInstanceOf( \stdClass::class, $unserialized );
		self::assertSame( 'https://new.example', $unserialized->url );
	}

	public function testRewritesJsonStructures(): void {
		$rewriter = new SerializedDataRewriter();

		$json = '{"ref": 12, "url": "http://subsite.com"}';

		$result = $rewriter->rewriteJson(
			$json,
			static function ( mixed $value ): mixed {
				if ( $value === 12 ) {
					return 45;
				}
				if ( is_string( $value ) ) {
					return str_replace( 'http://subsite.com', 'https://dest.com', $value );
				}
				return $value;
			}
		);

		$decoded = json_decode( $result, true );
		self::assertIsArray( $decoded );
		self::assertSame( 45, $decoded['ref'] );
		self::assertSame( 'https://dest.com', $decoded['url'] );
	}

	public function testRewriteAnyDetectsFormat(): void {
		$rewriter = new SerializedDataRewriter();

		$serialized = serialize( array( 'a' => 'foo' ) );
		$json       = '{"a":"foo"}';
		$plain      = 'foo';

		$replacer = static function ( mixed $value ): mixed {
			return is_string( $value ) ? str_replace( 'foo', 'bar', $value ) : $value;
		};

		$resSerialized = $rewriter->rewriteAny( $serialized, $replacer );
		$resJson       = $rewriter->rewriteAny( $json, $replacer );
		$resPlain      = $rewriter->rewriteAny( $plain, $replacer );

		self::assertTrue( $rewriter->isSerialized( $resSerialized ) );
		self::assertSame( array( 'a' => 'bar' ), unserialize( $resSerialized ) );

		self::assertTrue( $rewriter->isJson( $resJson ) );
		self::assertSame( array( 'a' => 'bar' ), json_decode( $resJson, true ) );

		self::assertSame( 'bar', $resPlain );
	}

	public function testReturnsUnserializedStringsUnchanged(): void {
		$rewriter = new SerializedDataRewriter();

		$notSerialized = 'just some un-serialized text with "quotes"';
		self::assertSame( $notSerialized, $rewriter->rewrite( $notSerialized, static fn( $v ) => $v ) );
	}
}
