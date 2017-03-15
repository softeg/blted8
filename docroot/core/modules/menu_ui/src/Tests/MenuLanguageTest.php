<?php

namespace Drupal\menu_ui\Tests;

use Drupal\Component\Utility\Unicode;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;

/**
 * Tests for menu_ui language settings.
 *
 * Create menu and menu links in non-English language, and edit language
 * settings.
 *
 * @group menu_ui
 */
class MenuLanguageTest extends MenuWebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('language');

  protected function setUp() {
    parent::setUp();

    $this->drupalLogin($this->drupalCreateUser(array('access administration pages', 'administer menu')));

    // Add some custom languages.
    foreach (array('aa', 'bb', 'cc', 'cs') as $language_code) {
      ConfigurableLanguage::create(array(
        'id' => $language_code,
        'label' => $this->randomMachineName(),
      ))->save();
    }
  }

  /**
   * Tests menu language settings and the defaults for menu link items.
   */
  function testMenuLanguage() {
    // Create a test menu to test the various language-related settings.
    // Machine name has to be lowercase.
    $menu_name = Unicode::strtolower($this->randomMachineName(16));
    $label = $this->randomString();
    $edit = array(
      'id' => $menu_name,
      'description' => '',
      'label' => $label,
      'langcode' => 'aa',
    );
    $this->drupalPostForm('admin/structure/menu/add', $edit, t('Save'));
    ContentLanguageSettings::loadByEntityTypeBundle('menu_link_content', 'menu_link_content')
      ->setDefaultLangcode('bb')
      ->setLanguageAlterable(TRUE)
      ->save();

    // Check menu language.
    $this->assertOptionSelected('edit-langcode', $edit['langcode'], 'The menu language was correctly selected.');

    // Test menu link language.
    $link_path = '/';

    // Add a menu link.
    $link_title = $this->randomString();
    $edit = array(
      'title[0][value]' => $link_title,
      'link[0][uri]' => $link_path,
    );
    $this->drupalPostForm("admin/structure/menu/manage/$menu_name/add", $edit, t('Save'));
    // Check the link was added with the correct menu link default language.
    $menu_links = entity_load_multiple_by_properties('menu_link_content', array('title' => $link_title));
    $menu_link = reset($menu_links);
    $this->assertMenuLink($menu_link->getPluginId(), array(
      'menu_name' => $menu_name,
      'route_name' => '<front>',
      'langcode' => 'bb',
    ));

    // Edit menu link default, changing it to cc.
    ContentLanguageSettings::loadByEntityTypeBundle('menu_link_content', 'menu_link_content')
      ->setDefaultLangcode('cc')
      ->setLanguageAlterable(TRUE)
      ->save();

    // Add a menu link.
    $link_title = $this->randomString();
    $edit = array(
      'title[0][value]' => $link_title,
      'link[0][uri]' => $link_path,
    );
    $this->drupalPostForm("admin/structure/menu/manage/$menu_name/add", $edit, t('Save'));
    // Check the link was added with the correct new menu link default language.
    $menu_links = entity_load_multiple_by_properties('menu_link_content', array('title' => $link_title));
    $menu_link = reset($menu_links);
    $this->assertMenuLink($menu_link->getPluginId(), array(
      'menu_name' => $menu_name,
      'route_name' => '<front>',
      'langcode' => 'cc',
    ));

    // Now change the language of the new link to 'bb'.
    $edit = array(
      'langcode[0][value]' => 'bb',
    );
    $this->drupalPostForm('admin/structure/menu/item/' . $menu_link->id() . '/edit', $edit, t('Save'));
    $this->assertMenuLink($menu_link->getPluginId(), array(
      'menu_name' => $menu_name,
      'route_name' => '<front>',
      'langcode' => 'bb',
    ));

    // Saving menu link items ends up on the edit menu page. To check the menu
    // link has the correct language default on edit, go to the menu link edit
    // page first.
    $this->drupalGet('admin/structure/menu/item/' . $menu_link->id() . '/edit');
    // Check that the language selector has the correct default value.
    $this->assertOptionSelected('edit-langcode-0-value', 'bb', 'The menu link language was correctly selected.');

    // Edit menu to hide the language select on menu link item add.
    ContentLanguageSettings::loadByEntityTypeBundle('menu_link_content', 'menu_link_content')
      ->setDefaultLangcode('cc')
      ->setLanguageAlterable(FALSE)
      ->save();

    // Check that the language selector is not available on menu link add page.
    $this->drupalGet("admin/structure/menu/manage/$menu_name/add");
    $this->assertNoField('edit-langcode-0-value', 'The language selector field was hidden the page');
  }

}
