<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Central old-ID => new-ID registry for a migration run (PLAN.md §5).
 * Every migrator records the destination IDs it assigns here so later
 * phases (posts needing author/term IDs, comments needing post IDs,
 * menu items needing object IDs) can rewrite references.
 *
 * Entries are keyed (entity type, source blog_id, source ID). Network-
 * global entities like users use site ID 0; canonical-level lookups
 * (e.g. "taxonomy|label" term keys) also use site ID 0 with the key
 * string as the source ID.
 *
 * The map persists to JSON (var/state/idmap-{run}.json) so an
 * interrupted run can resume without re-deriving assignments.
 *
 * @package MergeMultisite
 */
final class IdMap {

	/** @var array<string, array<string, int>> type => "siteId:oldId" => newId */
	private array $map = array();

	/**
	 * Record that source ($siteId, $oldId) became destination $newId.
	 * Pass siteId 0 for network-global entities (users).
	 */
	public function set( string $type, int $siteId, int|string $oldId, int $newId ): void {
		$this->map[ $type ][ $siteId . ':' . $oldId ] = $newId;
	}

	/**
	 * The destination ID for a source entity, or null if not migrated.
	 */
	public function get( string $type, int $siteId, int|string $oldId ): ?int {
		return $this->map[ $type ][ $siteId . ':' . $oldId ] ?? null;
	}

	/**
	 * Whether a source entity has been migrated.
	 */
	public function has( string $type, int $siteId, int|string $oldId ): bool {
		return isset( $this->map[ $type ][ $siteId . ':' . $oldId ] );
	}

	/**
	 * Every destination ID assignment for one entity type.
	 *
	 * @return array<string, int> "siteId:oldId" => newId
	 */
	public function entries( string $type ): array {
		return $this->map[ $type ] ?? array();
	}

	/**
	 * Total recorded assignments across all entity types.
	 */
	public function count(): int {
		$total = 0;
		foreach ( $this->map as $entries ) {
			$total += count( $entries );
		}

		return $total;
	}

	/**
	 * Persist the map as JSON so a later run can resume (PLAN.md §5).
	 */
	public function save( string $path ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0775, true );
		}
		file_put_contents( $path, json_encode( $this->map, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Load a previously saved map, or return an empty one when the
	 * file does not exist.
	 */
	public static function load( string $path ): self {
		$map = new self();
		if ( is_file( $path ) ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $data ) ) {
				$map->map = $data;
			}
		}

		return $map;
	}
}
