<?php

/**
 * @file
 * Contains post_update hooks for eca.
 */

/**
 * Clear ECA process debugger temp store after switch to JSON storage.
 *
 * The process debugger now stores its history as JSON instead of PHP
 * serialized data. Any history already in the temp store is serialized and can
 * no longer be decoded, so it is removed explicitly rather than left as
 * orphaned, undecodable blobs until its TTL expires. The backend-agnostic
 * key-value-expirable API is used so non-database backends (e.g. Redis) are
 * covered too.
 *
 * @see https://www.drupal.org/project/eca/issues/3585744
 */
function eca_post_update_clear_process_debugger_tempstore(): void {
  \Drupal::keyValueExpirable('tempstore.shared.eca_process_debugger')->deleteAll();
  \Drupal::keyValueExpirable('tempstore.private.eca_process_debugger')->deleteAll();
}
