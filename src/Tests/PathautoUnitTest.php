<?php

/**
 * @file
 * Contains \Drupal\pathauto\Tests\PathautoUnitTest.
 */

namespace Drupal\pathauto\Tests;

use Drupal\Core\Language\Language;
use Drupal\Component\Utility\String;
use Drupal\pathauto\PathautoManagerInterface;
use Drupal\simpletest\KernelTestBase;

/**
 * Unit tests for Pathauto functions.
 *
 * @group pathauto
 */
class PathautoUnitTest extends KernelTestBase {

  use PathautoTestHelperTrait;

  public static $modules = array('system', 'entity', 'field', 'text', 'user', 'node', 'path', 'pathauto', 'taxonomy', 'token', 'menu_link', 'filter');

  protected $currentUser;

  public function setUp() {
    parent::setup();

    $this->installConfig(array('pathauto', 'taxonomy', 'system'));

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');

    $this->installSchema('node', array('node_access'));
    $this->installSchema('system', array('url_alias', 'sequences', 'router'));

    \Drupal::service('router.builder')->rebuild();

    $this->currentUser = entity_create('user', array('name' => $this->randomMachineName()));
    $this->currentUser->save();
  }

  /**
   * Test _pathauto_get_schema_alias_maxlength().
   */
  public function testGetSchemaAliasMaxLength() {
    $this->assertIdentical(\Drupal::service('pathauto.alias_storage_helper')->getAliasSchemaMaxlength(), 255);
  }

  /**
   * Test pathauto_pattern_load_by_entity().
   */
  public function testPatternLoadByEntity() {
    \Drupal::config('pathauto.pattern')
      ->set('node.article._default', 'article/[node:title]')
      ->set('node.article.en', 'article/en/[node:title]')
      ->set('node.page._default', '[node:title]')
      ->save();

    $tests = array(
      array(
        'entity' => 'node',
        'bundle' => 'article',
        'language' => 'fr',
        'expected' => 'article/[node:title]',
      ),
      array(
        'entity' => 'node',
        'bundle' => 'article',
        'language' => 'en',
        'expected' => 'article/en/[node:title]',
      ),
      array(
        'entity' => 'node',
        'bundle' => 'article',
        'language' => Language::LANGCODE_NOT_SPECIFIED,
        'expected' => 'article/[node:title]',
      ),
      array(
        'entity' => 'node',
        'bundle' => 'page',
        'language' => 'en',
        'expected' => '[node:title]',
      ),
      array(
        'entity' => 'user',
        'bundle' => 'user',
        'language' => Language::LANGCODE_NOT_SPECIFIED,
        'expected' => 'users/[user:name]',
      ),
      array(
        'entity' => 'invalid-entity',
        'bundle' => '',
        'language' => Language::LANGCODE_NOT_SPECIFIED,
        'expected' => '',
      ),
    );
    foreach ($tests as $test) {
      $actual = \Drupal::service('pathauto.manager')->getPatternByEntity($test['entity'], $test['bundle'], $test['language']);
      $this->assertIdentical($actual, $test['expected'], t("pathauto_pattern_load_by_entity('@entity', '@bundle', '@language') returned '@actual', expected '@expected'", array(
        '@entity' => $test['entity'],
        '@bundle' => $test['bundle'],
        '@language' => $test['language'],
        '@actual' => $actual,
        '@expected' => $test['expected'],
      )));
    }
  }

  /**
   * Test pathauto_cleanstring().
   */
  public function testCleanString() {

    $config = \Drupal::configFactory()->get('pathauto.settings');

    $tests = array();
    $config->set('ignore_words', ', in, is,that, the  , this, with, ');
    $config->set('max_component_length', 35);
    $config->set('transliterate', TRUE);
    $config->save();
    \Drupal::service('pathauto.manager')->resetCaches();

    // Test the 'ignored words' removal.
    $tests['this'] = 'this';
    $tests['this with that'] = 'this-with-that';
    $tests['this thing with that thing'] = 'thing-thing';

    // Test length truncation and duplicate separator removal.
    $tests[' - Pathauto is the greatest - module ever in Drupal hiarticle - '] = 'pathauto-greatest-module-ever';

    // Test that HTML tags are removed.
    $tests['This <span class="text">text</span> has <br /><a href="http://example.com"><strong>HTML tags</strong></a>.'] = 'text-has-html-tags';
    $tests[String::checkPlain('This <span class="text">text</span> has <br /><a href="http://example.com"><strong>HTML tags</strong></a>.')] = 'text-has-html-tags';

    // Transliteration.
    $tests['ľščťžýáíéňô'] = 'lsctzyaieno';

    foreach ($tests as $input => $expected) {
      $output = \Drupal::service('pathauto.manager')->cleanString($input);
      $this->assertEqual($output, $expected, t("Drupal::service('pathauto.manager')->cleanString('@input') expected '@expected', actual '@output'", array(
        '@input' => $input,
        '@expected' => $expected,
        '@output' => $output,
      )));
    }
  }

  /**
   * Test pathauto_clean_alias().
   */
  public function testCleanAlias() {
    $tests = array();
    $tests['one/two/three'] = 'one/two/three';
    $tests['/one/two/three/'] = 'one/two/three';
    $tests['one//two///three'] = 'one/two/three';
    $tests['one/two--three/-/--/-/--/four---five'] = 'one/two-three/four-five';
    $tests['one/-//three--/four'] = 'one/three/four';

    foreach ($tests as $input => $expected) {
      $output = \Drupal::service('pathauto.alias_cleaner')->cleanAlias($input);
      $this->assertEqual($output, $expected, t("Drupal::service('pathauto.manager')->cleanAlias('@input') expected '@expected', actual '@output'", array(
        '@input' => $input,
        '@expected' => $expected,
        '@output' => $output,
      )));
    }
  }

  /**
   * Test pathauto_path_delete_multiple().
   */
  public function testPathDeleteMultiple() {
    $this->saveAlias('node/1', 'node-1-alias');
    $this->saveAlias('node/1/view', 'node-1-alias/view');
    $this->saveAlias('node/1', 'node-1-alias-en', 'en');
    $this->saveAlias('node/1', 'node-1-alias-fr', 'fr');
    $this->saveAlias('node/2', 'node-2-alias');

    pathauto_path_delete_all('node/1');
    $this->assertNoAliasExists(array('source' => "node/1"));
    $this->assertNoAliasExists(array('source' => "node/1/view"));
    $this->assertAliasExists(array('source' => "node/2"));
  }

  /**
   * Test the different update actions in \Drupal::service('pathauto.manager')->createAlias().
   */
  public function testUpdateActions() {
    $config = \Drupal::configFactory()->get('pathauto.settings');

    // Test PATHAUTO_UPDATE_ACTION_NO_NEW with unaliased node and 'insert'.
    $config->set('update_action', PathautoManagerInterface::UPDATE_ACTION_NO_NEW);
    $config->save();
    $node = $this->drupalCreateNode(array('title' => 'First title'));
    $this->assertEntityAlias($node, 'content/first-title');

    $node->path->pathauto = TRUE;

    // Default action is PATHAUTO_UPDATE_ACTION_DELETE.
    $config->set('update_action', PathautoManagerInterface::UPDATE_ACTION_DELETE);
    $config->save();
    $node->setTitle('Second title');
    pathauto_entity_update($node);
    $this->assertEntityAlias($node, 'content/second-title');
    $this->assertNoAliasExists(array('alias' => 'content/first-title'));

    // Test PATHAUTO_UPDATE_ACTION_LEAVE
    $config->set('update_action', PathautoManagerInterface::UPDATE_ACTION_LEAVE);
    $config->save();
    $node->setTitle('Third title');
    pathauto_entity_update($node);
    $this->assertEntityAlias($node, 'content/third-title');
    $this->assertAliasExists(array('source' => $node->getSystemPath(), 'alias' => 'content/second-title'));

    $config->set('update_action', PathautoManagerInterface::UPDATE_ACTION_DELETE);
    $config->save();
    $node->setTitle('Fourth title');
    pathauto_entity_update($node);
    $this->assertEntityAlias($node, 'content/fourth-title');
    $this->assertNoAliasExists(array('alias' => 'content/third-title'));
    // The older second alias is not deleted yet.
    $older_path = $this->assertAliasExists(array('source' => $node->getSystemPath(), 'alias' => 'content/second-title'));
    \Drupal::service('path.alias_storage')->delete($older_path);

    $config->set('update_action', PathautoManagerInterface::UPDATE_ACTION_NO_NEW);
    $config->save();
    $node->setTitle('Fifth title');
    pathauto_entity_update($node);
    $this->assertEntityAlias($node, 'content/fourth-title');
    $this->assertNoAliasExists(array('alias' => 'content/fifth-title'));

    // Test PATHAUTO_UPDATE_ACTION_NO_NEW with unaliased node and 'update'.
    $this->deleteAllAliases();
    pathauto_entity_update($node);
    $this->assertEntityAlias($node, 'content/fifth-title');

    // Test PATHAUTO_UPDATE_ACTION_NO_NEW with unaliased node and 'bulkupdate'.
    $this->deleteAllAliases();
    $node->setTitle('Sixth title');
    \Drupal::service('pathauto.manager')->updateEntity($node, 'bulkupdate');
    $this->assertEntityAlias($node, 'content/sixth-title');
  }

  /**
   * Test that \Drupal::service('pathauto.manager')->createAlias() will not create an alias for a pattern
   * that does not get any tokens replaced.
   */
  public function testNoTokensNoAlias() {
    $node = $this->drupalCreateNode(array('title' => ''));
    $this->assertNoEntityAliasExists($node);

    $node->setTitle('hello');
    pathauto_entity_update($node);
    $this->assertEntityAlias($node, 'content/hello');
  }

  /**
   * Test the handling of path vs non-path tokens in pathauto_clean_token_values().
   */
  public function testPathTokens() {
    $config = \Drupal::configFactory()->get('pathauto.pattern');
    $config->set('taxonomy_term._default', '[term:parent:url:path]/[term:name]');
    $config->save();

    $vocab = $this->addVocabulary();

    $term1 = $this->addTerm($vocab, array('name' => 'Parent term'));
    $this->assertEntityAlias($term1, 'parent-term');

    $term2 = $this->addTerm($vocab, array('name' => 'Child term', 'parent' => $term1->id()));
    $this->assertEntityAlias($term2, 'parent-term/child-term');

    $this->saveEntityAlias($term1, 'My Crazy/Alias/');
    pathauto_entity_update($term2);
    $this->assertEntityAlias($term2, 'My Crazy/Alias/child-term');
  }

  public function testEntityBundleRenamingDeleting() {
    $config = \Drupal::configFactory()->get('pathauto.pattern');

    // Create a vocabulary and test that it's pattern variable works.
    $vocab = $this->addVocabulary(array('vid' => 'old_name'));
    $config->set('taxonomy_term._default', 'base');
    $config->set('taxonomy_term.old_name._default', 'bundle');
    $config->save();

    $this->assertEntityPattern('taxonomy_term', 'old_name', Language::LANGCODE_NOT_SPECIFIED, 'bundle');

    // Rename the vocabulary's machine name, which should cause its pattern
    // variable to also be renamed.
    $vocab->vid = 'new_name';
    $vocab->save();
    $this->assertEntityPattern('taxonomy_term', 'new_name', Language::LANGCODE_NOT_SPECIFIED, 'bundle');
    $this->assertEntityPattern('taxonomy_term', 'old_name', Language::LANGCODE_NOT_SPECIFIED, 'base');

    // Delete the vocabulary, which should cause its pattern variable to also
    // be deleted.
    $vocab->delete();
    $this->assertEntityPattern('taxonomy_term', 'new_name', Language::LANGCODE_NOT_SPECIFIED, 'base');
  }

  function testNoExistingPathAliases() {

    \Drupal::configFactory()->get('pathauto.settings')
      ->set('punctuation_period', PathautoManagerInterface::PUNCTUATION_DO_NOTHING)
      ->save();
    \Drupal::configFactory()->get('pathauto.pattern')
      ->set('node.page._default', '[node:title]')
      ->save();
    \Drupal::service('pathauto.manager')->resetCaches();

    // Check that Pathauto does not create an alias of '/admin'.
    $node = $this->drupalCreateNode(array('title' => 'Admin', 'type' => 'page'));
    $this->assertEntityAlias($node, 'admin-0');

    // Check that Pathauto does not create an alias of '/modules'.
    $node->setTitle('Modules');
    $node->save();
    $this->assertEntityAlias($node, 'modules-0');

    // Check that Pathauto does not create an alias of '/index.php'.
    $node->setTitle('index.php');
    $node->save();
    $this->assertEntityAlias($node, 'index.php-0');

    // Check that a safe value gets an automatic alias. This is also a control
    // to ensure the above tests work properly.
    $node->setTitle('Safe value');
    $node->save();
    $this->assertEntityAlias($node, 'safe-value');
  }

  /**
   * Test programmatic entity creation for aliases.
   */
  function testProgrammaticEntityCreation() {
    $node = $this->drupalCreateNode(array('title' => 'Test node', 'path' => array('pathauto' => TRUE)));
    $this->assertEntityAlias($node, 'content/test-node');

    $vocabulary = $this->addVocabulary(array('name' => 'Tags'));
    $term = $this->addTerm($vocabulary, array('name' => 'Test term', 'path' => array('pathauto' => TRUE)));
    $this->assertEntityAlias($term, 'tags/test-term');

    $edit['name'] = 'Test user';
    $edit['mail'] = 'test-user@example.com';
    $edit['pass']   = user_password();
    $edit['path'] = array('pathauto' => TRUE);
    $edit['status'] = 1;
    $account = entity_create('user', $edit);
    $account->save();
    $this->assertEntityAlias($account, 'users/test-user');
  }

  protected function drupalCreateNode(array $settings = array()) {
    // Populate defaults array.
    $settings += array(
      'title'     => $this->randomMachineName(8),
      'type'      => 'page',
    );

    $node = entity_create('node', $settings);
    $node->save();

    return $node;
  }
}
