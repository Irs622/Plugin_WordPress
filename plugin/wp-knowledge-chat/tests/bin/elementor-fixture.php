<?php
// Creates an Elementor-built front page and a primary menu for layout-compatibility checks.
$data = [[
  'id' => 'a1b2c3d', 'elType' => 'container', 'settings' => ['content_width' => 'boxed', 'padding' => ['unit'=>'px','top'=>'80','right'=>'20','bottom'=>'80','left'=>'20','isLinked'=>false], 'background_background' => 'classic', 'background_color' => '#063232'],
  'elements' => [
    ['id' => 'e1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Strategic Advisory to Accelerate Sustainable and Scalable Growth', 'header_size' => 'h1', 'title_color' => '#FFFFFF'], 'elements' => []],
    ['id' => 'e2', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p style="color:#fff">Local compatibility test page built with Elementor (placeholder copy from the public homepage).</p>'], 'elements' => []],
    ['id' => 'e3', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => 'Connect with Us', 'link' => ['url' => '/contact/']], 'elements' => []],
  ],
], [
  'id' => 'f4e5d6c', 'elType' => 'container', 'settings' => ['padding' => ['unit'=>'px','top'=>'60','right'=>'20','bottom'=>'600','left'=>'20','isLinked'=>false]],
  'elements' => [
    ['id' => 'e4', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Who We Are', 'header_size' => 'h2'], 'elements' => []],
    ['id' => 'e5', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p>Long section to verify the widget stays fixed while scrolling and does not cover navigation.</p>'], 'elements' => []],
  ],
]];
$existing = get_page_by_path('home');
$id = $existing ? $existing->ID : wp_insert_post(['post_type' => 'page', 'post_title' => 'Home', 'post_name' => 'home', 'post_status' => 'publish']);
update_post_meta($id, '_elementor_edit_mode', 'builder');
update_post_meta($id, '_elementor_template_type', 'wp-page');
update_post_meta($id, '_wp_page_template', 'elementor_header_footer');
update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($data)));
update_option('show_on_front', 'page');
update_option('page_on_front', $id);

$menu = wp_get_nav_menu_object('Primary') ?: wp_get_nav_menu_object(wp_create_nav_menu('Primary'));
if (!wp_get_nav_menu_items($menu->term_id)) {
  foreach (['Home' => home_url('/'), 'About' => home_url('/about-us/'), 'Services' => home_url('/#services'), 'Contact' => home_url('/contact/')] as $title => $url) {
    wp_update_nav_menu_item($menu->term_id, 0, ['menu-item-title' => $title, 'menu-item-url' => $url, 'menu-item-status' => 'publish']);
  }
}
set_theme_mod('nav_menu_locations', ['menu-1' => $menu->term_id]);
echo "front page {$id}, menu {$menu->term_id}\n";
