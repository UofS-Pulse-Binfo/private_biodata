<?php
namespace Tests;

use StatonLab\TripalTestSuite\DBTransaction;
use StatonLab\TripalTestSuite\TripalTestCase;
use StatonLab\TripalTestSuite\Database\Factory;

class PermissionsTest extends TripalTestCase {
  // Uncomment to auto start and rollback db transactions per test method.
  use DBTransaction;

  /**
   * Test that our new permissions are available.
   * Specifically, `view private [bundle].`
   */
  public function testPermissionsAvailable() {

    $permissions = module_invoke_all('permission');

    // All bundle names are bio_data_##. Content types are created on install
    // of tripal_chado and thus all sites should have them available.
    $bundle_name = db_query('SELECT name FROM tripal_bundle limit 1')->fetchField();

    // First we want to check the permimssion added by this module is available.
    $our_permission = 'view private ' . $bundle_name;
    $this->assertArrayHasKey($our_permission, $permissions,
      "Our permission, $our_permission, was not available.");

    // Next we want to make sure the original permission is still there.
    $originial_permission = 'view ' . $bundle_name;
    $this->assertArrayHasKey($our_permission, $permissions,
      "The original view permission, $originial_permission, was not available.");
  }

  /**
   * Test private_biodata_TripalEntity_access().
   */
  public function testPrivateBiodataTripalEntityAccess() {
    $faker = \Faker\Factory::create();

    // Supress tripal errors
    putenv("TRIPAL_SUPPRESS_ERRORS=TRUE");

    // -- CREATE THE ENTITY.
    // All bundle names are bio_data_##. Content types are created on install
    // of tripal_chado and thus all sites should have them available.
    $bundle_id = db_query("SELECT bundle_id FROM chado_bundle WHERE data_table='pub'")->fetchField();
    $bundle_name = 'bio_data_' . $bundle_id;
    $bundle = tripal_load_bundle_entity(['name' => $bundle_name]);

    $record = factory('chado.pub')->create();
    $record_id = $record->pub_id;

    $ec = entity_get_controller('TripalEntity');
    $entity = $ec->create([
      'bundle' => $bundle_name,
      'term_id' => $bundle->term_id,
      'chado_record' => chado_generate_var('pub', ['pub_id' => $record_id], ['include_fk' => 0]),
      'chado_record_id' => $record_id,
      'publish' => TRUE,
      'bundle_object' => $bundle,
    ]);

    $entity = $entity->save($cache);
    $this->assertIsObject($entity, "Unable to create a test entity.");

    // -- CREATE USER WITH PERMISSIONS.
    $permissions = ["view private $bundle_name", "view $bundle_name"];
    $role_can = new \stdClass();
    $role_can->name = $faker->name();
    user_role_save($role_can);
    user_role_grant_permissions($role_can->rid, $permissions);
    $user_can = array(
      'name' => $faker->name(),
      'pass' => $faker->password(), // note: do not md5 the password
      'mail' => $email,
      'status' => 1,
      'init' => $email,
      'roles' => array(
        DRUPAL_AUTHENTICATED_RID => 'authenticated user',
        $role_can->rid => $role_can->name,
      ),
    );
    $user_can = user_save('', $user_can); // 1st param blank so new user is created.
    $user_can_uid = $user_can->uid;

    // -- TEST WITH ENTITY ID
    $can_access = private_biodata_access('view', $entity->id, $user_can);
    $this->assertTrue($can_access,
      "Should be able to pass in the entity ID to confirm access.");

    // -- TEST WITH ENTITY OBJECT
    $can_access = private_biodata_access('view', $entity, $user_can);
    $this->assertTrue($can_access,
      "Should be able to pass in the entity ID to confirm access.");

    // Unset tripal errors suppression
    putenv("TRIPAL_SUPPRESS_ERRORS");
  }

  /**
   * Test the permission for a given bundle.
   *
   * NOTE: We only test one bundle since it should be the same for all
   * of them (done in a loop).
   *
   * @group permissions
   */
  public function testPermissionsForUser() {
    $faker = \Faker\Factory::create();

    // Supress tripal errors
    putenv("TRIPAL_SUPPRESS_ERRORS=TRUE");

    // Create organism entity for testing.
    $bundle_name = 'bio_data_2';
    $bundle = tripal_load_bundle_entity(['name' => $bundle_name]);
    $genus = $faker->word(1, TRUE);
    $species = $faker->word(2, TRUE);
    $values = [
      'bundle' => $bundle_name,
      'term_id' => $bundle->term_id,
      'chado_table' => 'organism',
      'chado_column' => 'organism_id',
    ];
    $values['taxrank__genus']['und'][0] = [
      'value' => $genus,
      'chado-organism__genus' => $genus,
    ];
    $values['taxrank__species']['und'][0] = [
      'value' => $species,
      'chado-organism__species' => $species,
    ];
    $ec = entity_get_controller('TripalEntity');
    $entity = $ec->create($values);
    $entity = $entity->save();
    $entity_id = $entity->id;
    db_insert('private_biodata')->fields([
      'entity_id' => $entity_id,
      'private' => 1])->execute();

    $permission_name = "view private $bundle_name";

    // All permissions are assigned to users via roles...
    // Thus, create two new roles:
    // 1) A role which cannot use any of the permissions.
    $role_canNOT = new \stdClass();
    $role_canNOT->name = $faker->name();
    user_role_save($role_canNOT);
    // 2) A role which can use all of them.
    $role_can = new \stdClass();
    $role_can->name = $faker->name();
    user_role_save($role_can);
    user_role_grant_permissions($role_can->rid, [$permission_name]);

    // Create our users:
    // 1) a user without tripal permissions but who is still authenticated.
    $email = $faker->email();
    $user_canNOT = array(
      'name' => $faker->name(),
      'pass' => $faker->password(), // note: do not md5 the password
      'mail' => $email,
      'status' => 1,
      'init' => $email,
      'roles' => array(
        DRUPAL_AUTHENTICATED_RID => 'authenticated user',
        $role_canNOT->rid => $role_canNOT->name,
      ),
    );
    $user_canNOT = user_save('', $user_canNOT); // 1st param blank so new user is created.
    $user_canNOT_uid = $user_canNOT->uid;
    // 2) A user with the role giving them all tripal permissions.
    $email = $faker->email();
    $user_can = array(
      'name' => $faker->name(),
      'pass' => $faker->password(), // note: do not md5 the password
      'mail' => $email,
      'status' => 1,
      'init' => $email,
      'roles' => array(
        DRUPAL_AUTHENTICATED_RID => 'authenticated user',
        $role_can->rid => $role_can->name,
      ),
    );
    $user_can = user_save('', $user_can); // 1st param blank so new user is created.
    $user_can_uid = $user_can->uid;

    $entity_load = entity_load('TripalEntity', [$entity_id]);
    $entity = $entity_load[$entity_id];

    // Now we need to clear the user_access cache and re-load our users
    // in order to see our newly assigned roles and permissions reflected.
    drupal_static_reset('user_access');
    unset($user_can, $user_canNOT);
    $user_can = user_load($user_can_uid, TRUE);
    $user_canNOT = user_load($user_canNOT_uid, TRUE);

    // Check that our roles were assigned this permission correctly.
    $all_roles_with_permission = user_roles(TRUE, $permission_name);
    $this->assertArrayHasKey($role_can->rid, $all_roles_with_permission,
      "Our newly created role doesn't have the expected permission.");
    $this->assertArrayNotHasKey($role_canNOT->rid, $all_roles_with_permission,
      "The roles that shouldn't have the permission, does?");

    // Check that the user who should be able to access the content, can.
    $result = private_biodata_access('view', $entity, $user_can);
    $this->assertTrue($result,
      "The current user does not have permission to view the private entity.");

    // Check that the user who should NOT be able to access the content, can NOT.
    // Note we can only check if this permission is not given to the authenticated user.
    $has_authenticated = in_array(
      'authenticated user',
      $all_roles_with_permission
    );
    if ($has_authenticated == FALSE) {
      $result = private_biodata_access($op, $entity, $user_canNOT);
      $this->assertFalse($result,
        "The current user does but shouldn't have permission to view the private entity.");
    }

    // Unset tripal errors suppression
    putenv("TRIPAL_SUPPRESS_ERRORS");
  }
}
