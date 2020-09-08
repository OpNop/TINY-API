<?php 

if ( ! defined( 'WP_CACHE_KEY_SALT' ) ) {
	define( 'WP_CACHE_KEY_SALT', '' );
}

if ( ! defined( 'WP_REDIS_OBJECT_CACHE' ) ) {
	define( 'WP_REDIS_OBJECT_CACHE', true );
}

if ( ! defined( 'WP_REDIS_USE_CACHE_GROUPS' ) ) {
	define( 'WP_REDIS_USE_CACHE_GROUPS', false );
}

if ( ! defined( 'WP_REDIS_DEFAULT_EXPIRE_SECONDS' ) ) {
	define( 'WP_REDIS_DEFAULT_EXPIRE_SECONDS', 0 );
}

/**
 * WordPress Object Cache
 *
 * The WordPress Object Cache is used to save on trips to the database. The
 * Object Cache stores all of the cache data to memory and makes the cache
 * contents available by using a key, which is used to name and later retrieve
 * the cache contents.
 *
 * The Object Cache can be replaced by other caching mechanisms by placing files
 * in the wp-content folder which is looked at in wp-settings. If that file
 * exists, then this file will not be included.
 */
class WP_Object_Cache {

	/**
	 * Holds the cached objects
	 *
	 * @var array
	 * @access private
	 */
	var $cache = array();

	/**
	 * The amount of times the cache data was already stored in the cache.
	 *
	 * @access private
	 * @var int
	 */
	var $cache_hits = 0;

	/**
	 * Amount of times the cache did not have the request in cache
	 *
	 * @var int
	 * @access public
	 */
	var $cache_misses = 0;

	/**
	 * The amount of times a request was made to Redis
	 *
	 * @access private
	 * @var int
	 */
	var $redis_calls = array();

	/**
	 * List of global groups
	 *
	 * @var array
	 * @access protected
	 */
	var $global_groups = array();

	/**
	 * List of non-persistent groups
	 *
	 * @var array
	 * @access protected
	 */
	var $non_persistent_groups = array();

	/**
	 * List of groups which use Redis hashes.
	 *
	 * @var array
	 * @access protected
	 */
	var $redis_hash_groups = array();

	/**
	 * The blog prefix to prepend to keys in non-global groups.
	 *
	 * @var int
	 * @access private
	 */
	var $blog_prefix;

	/**
	 * Whether or not Redis is connected
	 *
	 * @var bool
	 * @access private
	 */
	var $is_redis_connected = false;

	/**
	 * Whether or not the object cache thinks Redis needs a flush
	 *
	 * @var bool
	 * @access private
	 */
	var $do_redis_failback_flush = false;

	/**
	 * The last triggered error
	 */
	var $last_triggered_error = '';

	/**
	 * Whether or not to use true cache groups, instead of flattening.
	 *
	 * @var bool
	 * @access private
	 */
	const USE_GROUPS = WP_REDIS_USE_CACHE_GROUPS;

	/**
	 * Adds data to the cache if it doesn't already exist.
	 *
	 * @uses WP_Object_Cache::_exists Checks to see if the cache already has data.
	 * @uses WP_Object_Cache::set Sets the data after the checking the cache
	 *     contents existence.
	 *
	 * @param int|string $key What to call the contents in the cache
	 * @param mixed $data The contents to store in the cache
	 * @param string $group Where to group the cache contents
	 * @param int $expire When to expire the cache contents
	 * @return bool False if cache key and group already exist, true on success
	 */
	public function add( $key, $data, $group = 'default', $expire = WP_REDIS_DEFAULT_EXPIRE_SECONDS ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}

		if ( $this->_exists( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the list of global groups.
	 *
	 * @param array $groups List of groups that are global.
	 */
	public function add_global_groups( $groups ) {
		$groups = (array) $groups;

		$groups              = array_fill_keys( $groups, true );
		$this->global_groups = array_merge( $this->global_groups, $groups );
	}

	/**
	 * Sets the list of non-persistent groups.
	 *
	 * @param array $groups List of groups that are non-persistent.
	 */
	public function add_non_persistent_groups( $groups ) {
		$groups = (array) $groups;

		$groups                      = array_fill_keys( $groups, true );
		$this->non_persistent_groups = array_merge( $this->non_persistent_groups, $groups );
	}

	/**
	 * Sets the list of groups that use Redis hashes.
	 *
	 * @param array $groups List of groups that use Redis hashes.
	 */
	public function add_redis_hash_groups( $groups ) {
		$groups = (array) $groups;

		$groups                  = array_fill_keys( $groups, true );
		$this->redis_hash_groups = array_merge( $this->redis_hash_groups, $groups );
	}

	/**
	 * Decrement numeric cache item's value
	 *
	 * @param int|string $key The cache key to increment
	 * @param int $offset The amount by which to decrement the item's value. Default is 1.
	 * @param string $group The group the key is in.
	 * @return false|int False on failure, the item's new value on success.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		// The key needs to exist in order to be decremented
		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		$offset = (int) $offset;

		// If this isn't a persistant group, we have to sort this out ourselves, grumble grumble.
		if ( ! $this->_should_persist( $group ) ) {
			$existing = $this->_get_internal( $key, $group );
			if ( empty( $existing ) || ! is_numeric( $existing ) ) {
				$existing = 0;
			} else {
				$existing -= $offset;
			}
			if ( $existing < 0 ) {
				$existing = 0;
			}
			$this->_set_internal( $key, $group, $existing );
			return $existing;
		}

		if ( $this->_should_use_redis_hashes( $group ) ) {
			$redis_safe_group = $this->_key( '', $group );
			$result           = $this->_call_redis( 'hIncrBy', $redis_safe_group, $key, -$offset, $group );
			if ( $result < 0 ) {
				$result = 0;
				$this->_call_redis( 'hSet', $redis_safe_group, $key, $result );
			}
		} else {
			$id     = $this->_key( $key, $group );
			$result = $this->_call_redis( 'decrBy', $id, $offset );
			if ( $result < 0 ) {
				$result = 0;
				$this->_call_redis( 'set', $id, $result );
			}
		}

		if ( is_int( $result ) ) {
			$this->_set_internal( $key, $group, $result );
		}
		return $result;
	}

	/**
	 * Remove the contents of the cache key in the group
	 *
	 * If the cache key does not exist in the group and $force parameter is set
	 * to false, then nothing will happen. The $force parameter is set to false
	 * by default.
	 *
	 * @param int|string $key What the contents in the cache are called
	 * @param string $group Where the cache contents are grouped
	 * @param bool $force Optional. Whether to force the unsetting of the cache
	 *     key in the group
	 * @return bool False if the contents weren't deleted and true on success
	 */
	public function delete( $key, $group = 'default', $force = false ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( ! $force && ! $this->_exists( $key, $group ) ) {
			return false;
		}

		if ( $this->_should_persist( $group ) ) {
			if ( $this->_should_use_redis_hashes( $group ) ) {
				$redis_safe_group = $this->_key( '', $group );
				$result           = $this->_call_redis( 'hDel', $redis_safe_group, $key );
			} else {
				$id     = $this->_key( $key, $group );
				$result = $this->_call_redis( 'del', $id );
			}
			if ( 1 !== $result ) {
				return false;
			}
		}

		$this->_unset_internal( $key, $group );
		return true;
	}

	/**
	 * Remove the contents of all cache keys in the group.
	 *
	 * @param string $group Where the cache contents are grouped.
	 * @return boolean True on success, false on failure.
	 */
	public function delete_group( $group ) {
		if ( ! $this->_should_use_redis_hashes( $group ) ) {
			return false;
		}

		$multisite_safe_group = $this->multisite && ! isset( $this->global_groups[ $group ] ) ? $this->blog_prefix . $group : $group;
		$redis_safe_group     = $this->_key( '', $group );
		if ( $this->_should_persist( $group ) ) {
			$result = $this->_call_redis( 'del', $redis_safe_group );
			if ( 1 !== $result ) {
				return false;
			}
		} elseif ( ! $this->_should_persist( $group ) && ! isset( $this->cache[ $multisite_safe_group ] ) ) {
			return false;
		}
		unset( $this->cache[ $multisite_safe_group ] );
		return true;
	}

	/**
	 * Clears the object cache of all data.
	 *
	 * By default, this will flush the session cache as well as Redis, but we
	 * can leave the redis cache intact if we want. This is helpful when, for
	 * instance, you're running a batch process and want to clear the session
	 * store to reduce the memory footprint, but you don't want to have to
	 * re-fetch all the values from the database.
	 *
	 * @param  bool $redis Should we flush redis as well as the session cache?
	 * @return bool Always returns true
	 */
	public function flush( $redis = true ) {
		$this->cache = array();
		if ( $redis ) {
			$this->_call_redis( 'flushdb' );
		}

		return true;
	}

	/**
	 * Retrieves the cache contents, if it exists
	 *
	 * The contents will be first attempted to be retrieved by searching by the
	 * key in the cache group. If the cache is hit (success) then the contents
	 * are returned.
	 *
	 * On failure, the number of cache misses will be incremented.
	 *
	 * @param int|string $key What the contents in the cache are called
	 * @param string $group Where the cache contents are grouped
	 * @param string $force Whether to force a refetch rather than relying on the local cache (default is false)
	 * @param bool $found Optional. Whether the key was found in the cache. Disambiguates a return of false, a storable value. Passed by reference. Default null.
	 * @return bool|mixed False on failure to retrieve contents or the cache contents on success
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		// Key is set internally, so we can use this value
		if ( $this->_isset_internal( $key, $group ) && ! $force ) {
			$this->cache_hits += 1;
			$found             = true;
			return $this->_get_internal( $key, $group );
		}

		// Not a persistent group, so don't try Redis if the value doesn't exist
		// internally
		if ( ! $this->_should_persist( $group ) ) {
			$this->cache_misses += 1;
			$found               = false;
			return false;
		}

		if ( $this->_should_use_redis_hashes( $group ) ) {
			$redis_safe_group = $this->_key( '', $group );
			$value            = $this->_call_redis( 'hGet', $redis_safe_group, $key );
		} else {
			$id    = $this->_key( $key, $group );
			$value = $this->_call_redis( 'get', $id );
		}

		// PhpRedis returns `false` when the key doesn't exist
		if ( false === $value ) {
			$this->cache_misses += 1;
			$found               = false;
			return false;
		}

		// All non-numeric values are serialized
		$value = is_numeric( $value ) ? intval( $value ) : unserialize( $value );

		$this->_set_internal( $key, $group, $value );
		$this->cache_hits += 1;
		$found             = true;
		return $value;
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 *
	 * @param array  $keys  Array of keys under which the cache contents are stored.
	 * @param string $group Optional. Where the cache contents are grouped. Default empty.
	 * @param bool   $force Optional. Whether to force an update of the local cache
	 *                      from the persistent cache. Default false.
	 * @return array Array of values organized into groups.
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		$cache = array();
		if ( ! $this->_should_persist( $group ) ) {
			foreach ( $keys as $key ) {
				$cache[ $key ] = $this->_isset_internal( $key, $group ) ? $this->_get_internal( $key, $group ) : false;
				false !== $cache[ $key ] ? $this->cache_hits++ : $this->cache_misses++;
			}
			return $cache;
		}

		// Attempt to fetch values from the internal cache.
		if ( ! $force ) {
			foreach ( $keys as $key ) {
				if ( $this->_isset_internal( $key, $group ) ) {
					$cache[ $key ] = $this->_get_internal( $key, $group );
					$this->cache_hits++;
				}
			}
		}
		$remaining_keys = array_values( array_diff( $keys, array_keys( $cache ) ) );
		// If all keys were satisfied by the internal cache, we're sorted.
		if ( empty( $remaining_keys ) ) {
			return $cache;
		}
		if ( $this->_should_use_redis_hashes( $group ) ) {
			$redis_safe_group = $this->_key( '', $group );
			$results          = $this->_call_redis( 'hmGet', $redis_safe_group, $remaining_keys );
			$results          = is_array( $results ) ? array_values( $results ) : $results;
		} else {
			$ids = array();
			foreach ( $remaining_keys as $key ) {
				$ids[] = $this->_key( $key, $group );
			}
			$results = $this->_call_redis( 'mget', $ids );
		}
		// Process the results from the Redis call.
		foreach ( $remaining_keys as $i => $key ) {
			$value = isset( $results[ $i ] ) ? $results[ $i ] : false;
			if ( false !== $value ) {
				// All non-numeric values are serialized
				$value = is_numeric( $value ) ? intval( $value ) : unserialize( $value );
				$this->_set_internal( $key, $group, $value );
				$this->cache_hits++;
			} else {
				$this->cache_misses++;
			}
			$cache[ $key ] = $value;
		}
		// Make sure return values are returned in the order of the passed keys.
		$return_cache = array();
		foreach ( $keys as $key ) {
			$return_cache[ $key ] = isset( $cache[ $key ] ) ? $cache[ $key ] : false;
		}
		return $return_cache;
	}

	/**
	 * Increment numeric cache item's value
	 *
	 * @param int|string $key The cache key to increment
	 * @param int $offset The amount by which to increment the item's value. Default is 1.
	 * @param string $group The group the key is in.
	 * @return false|int False on failure, the item's new value on success.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		// The key needs to exist in order to be incremented
		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		$offset = (int) $offset;

		// If this isn't a persistant group, we have to sort this out ourselves, grumble grumble.
		if ( ! $this->_should_persist( $group ) ) {
			$existing = $this->_get_internal( $key, $group );
			if ( empty( $existing ) || ! is_numeric( $existing ) ) {
				$existing = 1;
			} else {
				$existing += $offset;
			}
			if ( $existing < 0 ) {
				$existing = 0;
			}
			$this->_set_internal( $key, $group, $existing );
			return $existing;
		}

		if ( $this->_should_use_redis_hashes( $group ) ) {
			$redis_safe_group = $this->_key( '', $group );
			$result           = $this->_call_redis( 'hIncrBy', $redis_safe_group, $key, $offset, $group );
			if ( $result < 0 ) {
				$result = 0;
				$this->_call_redis( 'hSet', $redis_safe_group, $key, $result );
			}
		} else {
			$id     = $this->_key( $key, $group );
			$result = $this->_call_redis( 'incrBy', $id, $offset );
			if ( $result < 0 ) {
				$result = 0;
				$this->_call_redis( 'set', $id, $result );
			}
		}

		if ( is_int( $result ) ) {
			$this->_set_internal( $key, $group, $result );
		}
		return $result;
	}

	/**
	 * Replace the contents in the cache, if contents already exist
	 * @see WP_Object_Cache::set()
	 *
	 * @param int|string $key What to call the contents in the cache
	 * @param mixed $data The contents to store in the cache
	 * @param string $group Where to group the cache contents
	 * @param int $expire When to expire the cache contents
	 * @return bool False if not exists, true if contents were replaced
	 */
	public function replace( $key, $data, $group = 'default', $expire = WP_REDIS_DEFAULT_EXPIRE_SECONDS ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Reset keys
	 *
	 * @deprecated 3.5.0
	 */
	public function reset() {
		_deprecated_function( __FUNCTION__, '3.5', 'switch_to_blog()' );
	}

	/**
	 * Sets the data contents into the cache
	 *
	 * The cache contents is grouped by the $group parameter followed by the
	 * $key. This allows for duplicate ids in unique groups. Therefore, naming of
	 * the group should be used with care and should follow normal function
	 * naming guidelines outside of core WordPress usage.
	 *
	 * The $expire parameter is not used, because the cache will automatically
	 * expire for each time a page is accessed and PHP finishes. The method is
	 * more for cache plugins which use files.
	 *
	 * @param int|string $key What to call the contents in the cache
	 * @param mixed $data The contents to store in the cache
	 * @param string $group Where to group the cache contents
	 * @param int $expire TTL for the data, in seconds
	 * @return bool Always returns true
	 */
	public function set( $key, $data, $group = 'default', $expire = WP_REDIS_DEFAULT_EXPIRE_SECONDS ) {

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$this->_set_internal( $key, $group, $data );

		if ( ! $this->_should_persist( $group ) ) {
			return true;
		}

		// If this is an integer, store it as such. Otherwise, serialize it.
		if ( ! is_numeric( $data ) || intval( $data ) !== $data ) {
			$data = serialize( $data );
		}

		// Redis doesn't support expire on hash group keys
		if ( $this->_should_use_redis_hashes( $group ) ) {
			$redis_safe_group = $this->_key( '', $group );
			$this->_call_redis( 'hSet', $redis_safe_group, $key, $data );
			return true;
		}

		$id = $this->_key( $key, $group );
		if ( empty( $expire ) ) {
			$this->_call_redis( 'set', $id, $data );
		} else {
			$this->_call_redis( 'setex', $id, $expire, $data );
		}
		return true;
	}

	/**
	 * Echoes the stats of the caching.
	 *
	 * Gives the cache hits, and cache misses. Also prints every cached group,
	 * key and the data.
	 */
	public function stats() {
		$total_redis_calls = 0;
		foreach ( $this->redis_calls as $method => $calls ) {
			$total_redis_calls += $calls;
		}
		$out   = array();
		$out[] = '<p>';
		$out[] = '<strong>Cache Hits:</strong>' . (int) $this->cache_hits . '<br />';
		$out[] = '<strong>Cache Misses:</strong>' . (int) $this->cache_misses . '<br />';
		$out[] = '<strong>Redis Client:</strong>' . get_class( $this->redis ) . '<br />';
		$out[] = '<strong>Redis Calls:</strong>' . (int) $total_redis_calls . ':<br />';
		foreach ( $this->redis_calls as $method => $calls ) {
			$out[] = ' - ' . esc_html( $method ) . ': ' . (int) $calls . '<br />';
		}
		$out[] = '</p>';
		$out[] = '<ul>';
		foreach ( $this->cache as $group => $cache ) {
			$out[] = '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ( ' . number_format( strlen( serialize( $cache ) ) / 1024, 2 ) . 'k )</li>';
		}
		$out[] = '</ul>';
		// @codingStandardsIgnoreStart
		echo implode( PHP_EOL, $out );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Switch the interal blog id.
	 *
	 * This changes the blog id used to create keys in blog specific groups.
	 *
	 * @param int $blog_id Blog ID
	 */
	public function switch_to_blog( $blog_id ) {
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * Utility function to determine whether a key exists in the cache.
	 *
	 * @access protected
	 */
	protected function _exists( $key, $group ) {
		if ( $this->_isset_internal( $key, $group ) ) {
			return true;
		}

		if ( ! $this->_should_persist( $group ) ) {
			return false;
		}

		if ( $this->_should_use_redis_hashes( $group ) ) {
			$redis_safe_group = $this->_key( '', $group );
			return $this->_call_redis( 'hExists', $redis_safe_group, $key );
		}
		$id = $this->_key( $key, $group );
		return $this->_call_redis( 'exists', $id );
	}

	/**
	 * Check whether there's a value in the internal object cache.
	 *
	 * @param string $key
	 * @param string $group
	 * @return boolean
	 */
	protected function _isset_internal( $key, $group ) {
		if ( $this->_should_use_redis_hashes( $group ) ) {
			$multisite_safe_group = $this->multisite && ! isset( $this->global_groups[ $group ] ) ? $this->blog_prefix . $group : $group;
			return isset( $this->cache[ $multisite_safe_group ] ) && array_key_exists( $key, $this->cache[ $multisite_safe_group ] );
		} else {
			$key = $this->_key( $key, $group );
			return array_key_exists( $key, $this->cache );
		}
	}

	/**
	 * Get a value from the internal object cache
	 *
	 * @param string $key
	 * @param string $group
	 * @return mixed
	 */
	protected function _get_internal( $key, $group ) {
		$value = null;
		if ( $this->_should_use_redis_hashes( $group ) ) {
			$multisite_safe_group = $this->multisite && ! isset( $this->global_groups[ $group ] ) ? $this->blog_prefix . $group : $group;
			if ( isset( $this->cache[ $multisite_safe_group ] ) && array_key_exists( $key, $this->cache[ $multisite_safe_group ] ) ) {
				$value = $this->cache[ $multisite_safe_group ][ $key ];
			}
		} else {
			$key = $this->_key( $key, $group );
			if ( array_key_exists( $key, $this->cache ) ) {
				$value = $this->cache[ $key ];
			}
		}
		if ( is_object( $value ) ) {
			return clone $value;
		}
		return $value;
	}

	/**
	 * Set a value to the internal object cache
	 *
	 * @param string $key
	 * @param string $group
	 * @param mixed $value
	 */
	protected function _set_internal( $key, $group, $value ) {
		if ( $this->_should_use_redis_hashes( $group ) ) {
			$multisite_safe_group = $this->multisite && ! isset( $this->global_groups[ $group ] ) ? $this->blog_prefix . $group : $group;
			if ( ! isset( $this->cache[ $multisite_safe_group ] ) ) {
				$this->cache[ $multisite_safe_group ] = array();
			}
			$this->cache[ $multisite_safe_group ][ $key ] = $value;
		} else {
			$key                 = $this->_key( $key, $group );
			$this->cache[ $key ] = $value;
		}
	}

	/**
	 * Unset a value from the internal object cache
	 *
	 * @param string $key
	 * @param string $group
	 */
	protected function _unset_internal( $key, $group ) {
		if ( $this->_should_use_redis_hashes( $group ) ) {
			$multisite_safe_group = $this->multisite && ! isset( $this->global_groups[ $group ] ) ? $this->blog_prefix . $group : $group;
			if ( isset( $this->cache[ $multisite_safe_group ] ) && array_key_exists( $key, $this->cache[ $multisite_safe_group ] ) ) {
				unset( $this->cache[ $multisite_safe_group ][ $key ] );
			}
		} else {
			$key = $this->_key( $key, $group );
			if ( array_key_exists( $key, $this->cache ) ) {
				unset( $this->cache[ $key ] );
			}
		}
	}

	/**
	 * Utility function to generate the redis key for a given key and group.
	 *
	 * @param  string $key   The cache key.
	 * @param  string $group The cache group.
	 * @return string        A properly prefixed redis cache key.
	 */
	protected function _key( $key = '', $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( ! empty( $this->global_groups[ $group ] ) ) {
			$prefix = $this->global_prefix;
		} else {
			$prefix = $this->blog_prefix;
		}

		return preg_replace( '/\s+/', '', WP_CACHE_KEY_SALT . "$prefix$group:$key" );
	}

	/**
	 * Does this group use persistent storage?
	 *
	 * @param  string $group Cache group.
	 * @return bool        true if the group is persistent, false if not.
	 */
	protected function _should_persist( $group ) {
		return empty( $this->non_persistent_groups[ $group ] );
	}

	/**
	 * Should this group use Redis hashes?
	 *
	 * @param string $group Cache group.
	 * @return bool True if the group should use Redis hashes, false if not.
	 */
	protected function _should_use_redis_hashes( $group ) {
		if ( self::USE_GROUPS || ! empty( $this->redis_hash_groups[ $group ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Wrapper method for connecting to Redis, which lets us retry the connection
	 */
	protected function _connect_redis() {
		global $redis_server;

		$check_dependencies = array( $this, 'check_client_dependencies' );
		/**
		 * Permits alternate dependency check mechanism to be used.
		 *
		 * @param callable $check_dependencies Callback to execute.
		 */
		$check_dependencies = apply_filters( 'wp_redis_check_client_dependencies_callback', $check_dependencies );
		$dependencies_ok    = call_user_func( $check_dependencies );
		if ( true !== $dependencies_ok ) {
			$this->is_redis_connected    = false;
			$this->missing_redis_message = $dependencies_ok;
			return $this->is_redis_connected;
		}
		$client_parameters = $this->build_client_parameters( $redis_server );

		try {
			$client_connection = array( $this, 'prepare_client_connection' );
			/**
			 * Permits alternate initial client connection mechanism to be used.
			 *
			 * @param callable $client_connection Callback to execute.
			 */
			$client_connection = apply_filters( 'wp_redis_prepare_client_connection_callback', $client_connection );
			$this->redis       = call_user_func_array( $client_connection, array( $client_parameters ) );
		} catch ( Exception $e ) {
			$this->_exception_handler( $e );
			$this->is_redis_connected = false;
			return $this->is_redis_connected;
		}

		$keys_methods = array(
			'auth'     => 'auth',
			'database' => 'select',
		);

		try {
			$setup_connection = array( $this, 'perform_client_connection' );
			/**
			 * Permits alternate setup client connection mechanism to be used.
			 *
			 * @param callable $setup_connection Callback to execute.
			 */
			$setup_connection = apply_filters( 'wp_redis_perform_client_connection_callback', $setup_connection );
			call_user_func_array( $setup_connection, array( $this->redis, $client_parameters, $keys_methods ) );
		} catch ( Exception $e ) {
			$this->_exception_handler( $e );
			$this->is_redis_connected = false;
			return $this->is_redis_connected;
		}

		$this->is_redis_connected = $this->redis->isConnected();
		if ( ! $this->is_redis_connected ) {
			$this->missing_redis_message = 'Warning! WP Redis object cache cannot connect to Redis server.';
		}
		return $this->is_redis_connected;
	}

	/**
	 * Are the required dependencies for connecting to Redis available?
	 *
	 * @return mixed True if the required dependencies are present, string if
	 *               not with a message describing the issue.
	 */
	public function check_client_dependencies() {
		if ( ! class_exists( 'Redis' ) ) {
			return 'Warning! PHPRedis extension is unavailable, which is required by WP Redis object cache.';
		}
		return true;
	}

	/**
	 * Builds an array to be passed to a function that will set up the Redis
	 * client.
	 *
	 * @param array $redis_server Parameters used to construct a Redis client.
	 * @return array Final parameters to use to contruct a Redis client with
	 *               with defaults applied.
	 */
	public function build_client_parameters( $redis_server ) {
		if ( empty( $redis_server ) ) {
			// Attempt to automatically load Pantheon's Redis config from the env.
			if ( isset( $_SERVER['CACHE_HOST'] ) ) {
				$redis_server = array(
					'host'     => $_SERVER['CACHE_HOST'],
					'port'     => $_SERVER['CACHE_PORT'],
					'auth'     => $_SERVER['CACHE_PASSWORD'],
					'database' => isset( $_SERVER['CACHE_DB'] ) ? $_SERVER['CACHE_DB'] : 0,
				);
			} else {
				$redis_server = array(
					'host'     => '127.0.0.1',
					'port'     => 6379,
					'database' => 0,
				);
			}
		}

		if ( file_exists( $redis_server['host'] ) && 'socket' === filetype( $redis_server['host'] ) ) { //unix socket connection
			//port must be null or socket won't connect
			$port = null;
		} else { //tcp connection
			$port = ! empty( $redis_server['port'] ) ? $redis_server['port'] : 6379;
		}

		$defaults = array(
			'host'           => $redis_server['host'],
			'port'           => $port,
			'timeout'        => 1000, // I multiplied this by 1000 so we'd have a common measure of ms instead of s and ms, need to make sure this gets divided by 1000
			'retry_interval' => 100,
		);
		// 1s timeout, 100ms delay between reconnections

		// merging the defaults with the original $redis_server enables any
		// custom parameters to get sent downstream to the redis client.
		return array_replace_recursive( $redis_server, $defaults );
	}

	/**
	 * Constructs a PHPRedis Redis client.
	 *
	 * @param array $client_parameters Parameters used to construct a Redis client.
	 * @return Redis Redis client.
	 */
	public function prepare_client_connection( $client_parameters ) {
		$redis = new Redis;

		$redis->connect(
			$client_parameters['host'],
			$client_parameters['port'],
			// $client_parameters['timeout'] is sent in milliseconds,
			// connect() takes seconds, so divide by 1000
			$client_parameters['timeout'] / 1000,
			null,
			$client_parameters['retry_interval']
		);

		return $redis;
	}

	/**
	 * Sets up the Redis connection (ie authentication and specific database).
	 *
	 * @param Redis $redis Redis client.
	 * @param array $client_parameters Parameters used to configure Redis.
	 * @param array $keys_methods Associative array of keys from
	 *              $client_parameters to use as method arguments for $redis.
	 * @return bool True if successful.
	 */
	public function perform_client_connection( $redis, $client_parameters, $keys_methods ) {
		foreach ( $keys_methods as $key => $method ) {
			if ( ! isset( $client_parameters[ $key ] ) ) {
				continue;
			}
			try {
				$redis->$method( $client_parameters[ $key ] );
			} catch ( RedisException $e ) {

				// PhpRedis throws an Exception when it fails a server call.
				// To prevent WordPress from fataling, we catch the Exception.
				throw new Exception( $e->getMessage(), $e->getCode(), $e );
			}
		}
		return true;
	}

	/**
	 * Wrapper method for calls to Redis, which fails gracefully when Redis is unavailable
	 *
	 * @param string $method
	 * @param mixed $args
	 * @return mixed
	 */
	protected function _call_redis( $method ) {
		global $wpdb;

		$arguments = func_get_args();
		array_shift( $arguments ); // ignore $method

		// $group is intended for the failback, and isn't passed to the Redis callback
		if ( 'hIncrBy' === $method ) {
			$group = array_pop( $arguments );
		}

		if ( $this->is_redis_connected ) {
			try {
				if ( ! isset( $this->redis_calls[ $method ] ) ) {
					$this->redis_calls[ $method ] = 0;
				}
				$this->redis_calls[ $method ]++;
				$retval = call_user_func_array( array( $this->redis, $method ), $arguments );
				return $retval;
			} catch ( Exception $e ) {
				$retry_exception_messages = $this->retry_exception_messages();
				// PhpRedis throws an Exception when it fails a server call.
				// To prevent WordPress from fataling, we catch the Exception.
				if ( $this->exception_message_matches( $e->getMessage(), $retry_exception_messages ) ) {

					$this->_exception_handler( $e );

					// Attempt to refresh the connection if it was successfully established once
					// $this->is_redis_connected will be set inside _connect_redis()
					if ( $this->_connect_redis() ) {
						return call_user_func_array( array( $this, '_call_redis' ), array_merge( array( $method ), $arguments ) );
					}
					// Fall through to fallback below
				} else {
					throw $e;
				}
			}
		} // End if().

		if ( $this->is_redis_failback_flush_enabled() && ! $this->do_redis_failback_flush && ! empty( $wpdb ) ) {
			if ( $this->multisite ) {
				$table = $wpdb->sitemeta;
				$col1  = 'meta_key';
				$col2  = 'meta_value';
			} else {
				$table = $wpdb->options;
				$col1  = 'option_name';
				$col2  = 'option_value';
			}
			// @codingStandardsIgnoreStart
			$wpdb->query( "INSERT IGNORE INTO {$table} ({$col1},{$col2}) VALUES ('wp_redis_do_redis_failback_flush',1)" );
			// @codingStandardsIgnoreEnd
			$this->do_redis_failback_flush = true;
		}

		// Mock expected behavior from Redis for these methods
		switch ( $method ) {
			case 'incr':
			case 'incrBy':
				$val    = $this->cache[ $arguments[0] ];
				$offset = isset( $arguments[1] ) && 'incrBy' === $method ? $arguments[1] : 1;
				$val    = $val + $offset;
				return $val;
			case 'hIncrBy':
				$val = $this->_get_internal( $arguments[1], $group );
				return $val + $arguments[2];
			case 'decrBy':
			case 'decr':
				$val    = $this->cache[ $arguments[0] ];
				$offset = isset( $arguments[1] ) && 'decrBy' === $method ? $arguments[1] : 1;
				$val    = $val - $offset;
				return $val;
			case 'del':
			case 'hDel':
				return 1;
			case 'flushAll':
			case 'flushdb':
			case 'IsConnected':
			case 'exists':
			case 'get':
			case 'mget':
			case 'hGet':
			case 'hmGet':
				return false;
		}

	}

	/**
	 * Returns a filterable array of expected Exception messages that may be thrown
	 *
	 * @return array Array of expected exception messages
	 */
	public function retry_exception_messages() {
		$retry_exception_messages = array( 'socket error on read socket', 'Connection closed', 'Redis server went away' );
		return apply_filters( 'wp_redis_retry_exception_messages', $retry_exception_messages );
	}

	/**
	 * Compares individual message to list of messages.
	 *
	 * @param string $error Message to compare
	 * @param array $errors Array of messages to compare to
	 * @return bool whether $error matches any items in $errors
	 */
	public function exception_message_matches( $error, $errors ) {
		foreach ( $errors as $message ) {
			$pattern = $this->_format_message_for_pattern( $message );
			$matches = (bool) preg_match( $pattern, $error );
			if ( $matches ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prepends and appends '/' if not present in a string
	 *
	 * @param string $message Potential regex string that may need '/'
	 * @return string Regex pattern
	 */
	protected function _format_message_for_pattern( $message ) {
		$var = $message;
		$var = '/' === $var[0] ? $var : '/' . $var;
		$var = '/' === $var[ strlen( $var ) - 1 ] ? $var : $var . '/';
		return $var;
	}

	/**
	 * Handles exceptions by triggering a php error.
	 *
	 * @param Exception $exception
	 * @return null
	 */
	protected function _exception_handler( $exception ) {
		try {
			$this->last_triggered_error = 'WP Redis: ' . $exception->getMessage();
			// Be friendly to developers debugging production servers by triggering an error
			// @codingStandardsIgnoreStart
			trigger_error( $this->last_triggered_error, E_USER_WARNING );
			// @codingStandardsIgnoreEnd
		} catch ( PHPUnit_Framework_Error_Warning $e ) {
			// PHPUnit throws an Exception when `trigger_error()` is called.
			// To ensure our tests (which expect Exceptions to be caught) continue to run,
			// we catch the PHPUnit exception and inspect the RedisException message
		}
	}

	/**
	 * Admin UI to let the end user know something about the Redis connection isn't working.
	 */
	public function wp_action_admin_notices_warn_missing_redis() {
		if ( ! current_user_can( 'manage_options' ) || empty( $this->missing_redis_message ) ) {
			return;
		}
		echo '<div class="message error"><p>' . esc_html( $this->missing_redis_message ) . '</p></div>';
	}

	/**
	 * Whether or not wakeup flush is enabled
	 *
	 * @return bool
	 */
	private function is_redis_failback_flush_enabled() {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return false;
		} elseif ( defined( 'WP_REDIS_DISABLE_FAILBACK_FLUSH' ) && WP_REDIS_DISABLE_FAILBACK_FLUSH ) {
			return false;
		}
		return true;
	}

	/**
	 * Sets up object properties; PHP 5 style constructor
	 *
	 * @return null|WP_Object_Cache If cache is disabled, returns null.
	 */
	public function __construct() {
		global $blog_id, $table_prefix, $wpdb;

		$this->multisite   = is_multisite();
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';

		if ( ! $this->_connect_redis() && function_exists( 'add_action' ) ) {
			add_action( 'admin_notices', array( $this, 'wp_action_admin_notices_warn_missing_redis' ) );
		}

		if ( $this->is_redis_failback_flush_enabled() && ! empty( $wpdb ) ) {
			if ( $this->multisite ) {
				$table = $wpdb->sitemeta;
				$col1  = 'meta_key';
				$col2  = 'meta_value';
			} else {
				$table = $wpdb->options;
				$col1  = 'option_name';
				$col2  = 'option_value';
			}
			// @codingStandardsIgnoreStart
			$this->do_redis_failback_flush = (bool) $wpdb->get_results( "SELECT {$col2} FROM {$table} WHERE {$col1}='wp_redis_do_redis_failback_flush'" );
			// @codingStandardsIgnoreEnd
			if ( $this->is_redis_connected && $this->do_redis_failback_flush ) {
				$ret = $this->_call_redis( 'flushdb' );
				if ( $ret ) {
					// @codingStandardsIgnoreStart
					$wpdb->query( "DELETE FROM {$table} WHERE {$col1}='wp_redis_do_redis_failback_flush'" );
					// @codingStandardsIgnoreEnd
					$this->do_redis_failback_flush = false;
				}
			}
		}

		$this->global_prefix = ( $this->multisite || defined( 'CUSTOM_USER_TABLE' ) && defined( 'CUSTOM_USER_META_TABLE' ) ) ? '' : $table_prefix;

		/**
		 * @todo This should be moved to the PHP4 style constructor, PHP5
		 * already calls __destruct()
		 */
		register_shutdown_function( array( $this, '__destruct' ) );
	}

	/**
	 * Will save the object cache before object is completely destroyed.
	 *
	 * Called upon object destruction, which should be when PHP ends.
	 *
	 * @return bool True value. Won't be used by PHP
	 */
	public function __destruct() {
		return true;
	}
}