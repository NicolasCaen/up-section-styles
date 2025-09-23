<?php
/**
 * Plugin Name: Up Section Styles
 * Description: Register a custom post type to define section style presets and merge them into theme.json at runtime with an editor live preview.
 * Author: GEHIN NICOLAS
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Up_Section_Styles_Plugin {
    const CPT = 'section_style';
    const META_EXPORT = '_up_section_style_export';
    const META_BLOCKTYPES = '_up_section_style_blocktypes';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'init', [ $this, 'register_meta' ] );
        // Disable Gutenberg sidebar UI in favor of classic metabox per user preference
        // add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
        add_filter( 'wp_theme_json_data_theme', [ $this, 'filter_theme_json_data' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        // Save metas first
        add_action( 'save_post_' . self::CPT, [ $this, 'save_meta_box' ], 10, 3 );
        // Then write file after metas are persisted
        add_action( 'save_post_' . self::CPT, [ $this, 'maybe_write_style_file' ], 20, 3 );
        add_action( 'admin_notices', [ $this, 'maybe_render_admin_notice' ] );
    }

    private function infer_styles_from_content( $post_id ) {
        $content = get_post_field( 'post_content', $post_id );
        if ( ! $content ) return [];

        $blocks = parse_blocks( $content );
        if ( ! is_array( $blocks ) ) return [];

        $result = [ 'styles' => [] ];

        $acc = [
            'background' => null,
            'text' => null,
            'heading_text' => null,
            'button_bg' => null,
            'button_text' => null,
            'link_text' => null,
            'elements' => [], // collect element-level styles e.g. link/button/h2
        ];

        $walker = function( $b, &$acc ) use ( &$walker ) {
            foreach ( $b as $block ) {
                if ( empty( $block['blockName'] ) ) {
                    if ( ! empty( $block['innerBlocks'] ) ) {
                        $walker( $block['innerBlocks'], $acc );
                    }
                    continue;
                }
                $name = $block['blockName'];
                $attrs = isset( $block['attrs'] ) ? $block['attrs'] : [];

                // Group / Cover background & text
                if ( in_array( $name, [ 'core/group', 'core/cover' ], true ) ) {
                    $bg = $this->resolve_color_from_attrs( $attrs, 'background' );
                    if ( ! $acc['background'] && $bg ) $acc['background'] = $bg;
                    $txt = $this->resolve_color_from_attrs( $attrs, 'text' );
                    if ( ! $acc['text'] && $txt ) $acc['text'] = $txt;
                }

                // Heading text color
                if ( $name === 'core/heading' ) {
                    $h = $this->resolve_color_from_attrs( $attrs, 'text' );
                    if ( ! $acc['heading_text'] && $h ) $acc['heading_text'] = $h;
                }

                // Button colors
                if ( $name === 'core/button' ) {
                    $bbg = $this->resolve_color_from_attrs( $attrs, 'background' );
                    if ( ! $acc['button_bg'] && $bbg ) $acc['button_bg'] = $bbg;
                    $btx = $this->resolve_color_from_attrs( $attrs, 'text' );
                    if ( ! $acc['button_text'] && $btx ) $acc['button_text'] = $btx;
                }

                // Element-level styles defined on the block (e.g., group has style.elements)
                if ( isset( $attrs['style']['elements'] ) && is_array( $attrs['style']['elements'] ) ) {
                    $els = $attrs['style']['elements'];
                    $element_keys = [ 'link', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];
                    foreach ( $element_keys as $ek ) {
                        if ( isset( $els[ $ek ]['color'] ) && is_array( $els[ $ek ]['color'] ) ) {
                            $c = $els[ $ek ]['color'];
                            if ( isset( $c['text'] ) ) {
                                $val = $this->normalize_color_value( $c['text'] );
                                if ( $val ) {
                                    $acc['elements'][ $ek ]['color']['text'] = $val;
                                }
                            }
                            if ( isset( $c['background'] ) ) {
                                $val = $this->normalize_color_value( $c['background'] );
                                if ( $val ) {
                                    $acc['elements'][ $ek ]['color']['background'] = $val;
                                }
                            }
                        }
                    }
                }

                // Recurse
                if ( ! empty( $block['innerBlocks'] ) ) {
                    $walker( $block['innerBlocks'], $acc );
                }
            }
        };

        $walker( $blocks, $acc );

        // Build styles object
        $styles = [];
        if ( $acc['background'] || $acc['text'] ) {
            $styles['color'] = [];
            if ( $acc['background'] ) $styles['color']['background'] = $acc['background'];
            if ( $acc['text'] ) $styles['color']['text'] = $acc['text'];
        }

        if ( $acc['button_bg'] || $acc['button_text'] ) {
            $styles['blocks']['core/button']['color'] = [];
            if ( $acc['button_bg'] ) $styles['blocks']['core/button']['color']['background'] = $acc['button_bg'];
            if ( $acc['button_text'] ) $styles['blocks']['core/button']['color']['text'] = $acc['button_text'];
        }

        if ( $acc['heading_text'] ) {
            $styles['elements']['heading']['color']['text'] = $acc['heading_text'];
        }

        // Merge element-level discoveries (link, button, h2, ...)
        if ( ! empty( $acc['elements'] ) ) {
            foreach ( $acc['elements'] as $ek => $edata ) {
                if ( ! empty( $edata['color'] ) ) {
                    if ( isset( $edata['color']['text'] ) ) {
                        $styles['elements'][ $ek ]['color']['text'] = $edata['color']['text'];
                    }
                    if ( isset( $edata['color']['background'] ) ) {
                        $styles['elements'][ $ek ]['color']['background'] = $edata['color']['background'];
                    }
                }
            }
        }

        // Provide sensible defaults for link if not found explicitly
        if ( ! isset( $styles['elements']['link']['color']['text'] ) && $acc['button_text'] ) {
            $styles['elements']['link']['color']['text'] = $acc['button_text'];
        }
        $styles['elements']['link']['typography']['textDecoration'] = 'none';

        return [ 'styles' => $styles ];
    }

    private function resolve_color_from_attrs( $attrs, $type ) {
        // $type = 'background'|'text'
        $style_path = isset( $attrs['style']['color'] ) ? $attrs['style']['color'] : [];
        $presetSlug = null;
        if ( $type === 'background' ) {
            $presetSlug = isset( $attrs['backgroundColor'] ) ? $attrs['backgroundColor'] : ( isset( $attrs['overlayColor'] ) ? $attrs['overlayColor'] : null );
            $direct = isset( $style_path['background'] ) ? $style_path['background'] : null;
        } else {
            $presetSlug = isset( $attrs['textColor'] ) ? $attrs['textColor'] : null;
            $direct = isset( $style_path['text'] ) ? $style_path['text'] : null;
        }

        if ( $presetSlug ) {
            return 'var(--wp--preset--color--' . sanitize_title( $presetSlug ) . ')';
        }
        if ( is_string( $direct ) && $direct !== '' ) {
            return $direct; // Could be hex or var()
        }
        return null;
    }

    private function normalize_color_value( $val ) {
        if ( ! is_string( $val ) || $val === '' ) return null;
        // Convert var:preset|color|slug to CSS var
        if ( strpos( $val, 'var:preset|color|' ) === 0 ) {
            $slug = substr( $val, strlen( 'var:preset|color|' ) );
            $slug = sanitize_title( $slug );
            return 'var(--wp--preset--color--' . $slug . ')';
        }
        return $val;
    }

    public function register_cpt() {
        $labels = [
            'name' => __( 'Section Styles', 'up' ),
            'singular_name' => __( 'Section Style', 'up' ),
            'add_new' => __( 'Add New', 'up' ),
            'add_new_item' => __( 'Add New Section Style', 'up' ),
            'edit_item' => __( 'Edit Section Style', 'up' ),
            'new_item' => __( 'New Section Style', 'up' ),
            'view_item' => __( 'View Section Style', 'up' ),
            'search_items' => __( 'Search Section Styles', 'up' ),
            'not_found' => __( 'No Section Styles found', 'up' ),
            'not_found_in_trash' => __( 'No Section Styles found in Trash', 'up' ),
        ];

        $args = [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-art',
            'supports' => [ 'title', 'editor' ],
            'has_archive' => false,
        ];

        register_post_type( self::CPT, $args );
    }

    public function register_meta() {
        register_post_meta( self::CPT, self::META_EXPORT, [
            'type' => 'boolean',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
            'default' => false,
        ] );

        register_post_meta( self::CPT, self::META_BLOCKTYPES, [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => [
                'schema' => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'string' ],
                ],
            ],
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
            'default' => [ 'core/group', 'core/columns', 'core/column', 'core/cover' ],
        ] );
    }

    // Removed Gutenberg sidebar assets; metabox-only flow

    public function filter_theme_json_data( $theme_json ) {
        if ( ! $theme_json instanceof WP_Theme_JSON ) {
            return $theme_json;
        }

        $styles = $this->collect_styles_from_posts();
        if ( empty( $styles ) ) {
            return $theme_json;
        }

        // Merge each style object into theme.json data.
        $data = $theme_json->get_data();

        foreach ( $styles as $style_obj ) {
            if ( isset( $style_obj['styles'] ) && is_array( $style_obj['styles'] ) ) {
                $data['styles'] = isset( $data['styles'] ) && is_array( $data['styles'] )
                    ? $this->deep_merge( $data['styles'], $style_obj['styles'] )
                    : $style_obj['styles'];
            }

            if ( isset( $style_obj['blockTypes'] ) && is_array( $style_obj['blockTypes'] ) ) {
                // Optional: Could use this to scope styles by block in the future.
            }
        }

        return new WP_Theme_JSON( $data, 'theme' );
    }

    private function collect_styles_from_posts() {
        $query = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $all = [];
        foreach ( $query->posts as $post_id ) {
            $inferred = $this->infer_styles_from_content( $post_id );
            if ( ! empty( $inferred ) && is_array( $inferred ) ) {
                $all[] = $inferred;
            }
        }
        return $all;
    }

    private function deep_merge( $base, $extra ) {
        foreach ( $extra as $key => $value ) {
            if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $value ) ) {
                $base[ $key ] = $this->deep_merge( $base[ $key ], $value );
            } else {
                $base[ $key ] = $value;
            }
        }
        return $base;
    }

    // Removed example JSON helper; inference is used instead

    public function register_rest_routes() {
        register_rest_route( 'up-section-styles/v1', '/export', [
            'methods' => 'GET',
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'callback' => function( WP_REST_Request $req ) {
                $styles = $this->collect_styles_from_posts();
                return rest_ensure_response( $styles );
            }
        ] );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'up_section_styles_box',
            __( 'Section Style Options', 'up' ),
            [ $this, 'render_meta_box' ],
            self::CPT,
            'side',
            'default'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'up_ss_save', 'up_ss_nonce' );
        $export = (bool) get_post_meta( $post->ID, self::META_EXPORT, true );
        $blocktypes = get_post_meta( $post->ID, self::META_BLOCKTYPES, true );
        if ( ! is_array( $blocktypes ) || empty( $blocktypes ) ) {
            $blocktypes = [ 'core/group', 'core/columns', 'core/column', 'core/cover' ];
        }
        $blocktypes_str = implode( ', ', array_map( 'sanitize_text_field', $blocktypes ) );
        echo '<p><label><input type="checkbox" name="up_ss_export" value="1" ' . checked( $export, true, false ) . ' /> ' . esc_html__( 'Exporter en fichier du thème', 'up' ) . '</label></p>';
        echo '<p><label>' . esc_html__( 'BlockTypes (séparés par des virgules)', 'up' ) . '<br/>';
        echo '<input type="text" class="widefat" name="up_ss_blocktypes" value="' . esc_attr( $blocktypes_str ) . '" placeholder="core/group, core/columns, core/column, core/cover" />';
        echo '</label></p>';
        echo '<p class="description">' . esc_html__( 'Les styles seront déduits automatiquement à partir des blocs du contenu (group/cover, heading, button, etc.).', 'up' ) . '</p>';
    }

    public function save_meta_box( $post_id, $post, $update ) {
        if ( ! isset( $_POST['up_ss_nonce'] ) || ! wp_verify_nonce( $_POST['up_ss_nonce'], 'up_ss_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $export = isset( $_POST['up_ss_export'] ) ? (bool) $_POST['up_ss_export'] : false;
        $blocktypes_str = isset( $_POST['up_ss_blocktypes'] ) ? (string) wp_unslash( $_POST['up_ss_blocktypes'] ) : '';
        $blocktypes = array_filter( array_map( 'trim', explode( ',', $blocktypes_str ) ) );
        if ( empty( $blocktypes ) ) {
            $blocktypes = [ 'core/group', 'core/columns', 'core/column', 'core/cover' ];
        }

        update_post_meta( $post_id, self::META_EXPORT, $export );
        update_post_meta( $post_id, self::META_BLOCKTYPES, $blocktypes );
    }

    public function maybe_write_style_file( $post_id, $post, $update ) {
        // Avoid autosave/REST revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $export = (bool) get_post_meta( $post_id, self::META_EXPORT, true );
        if ( ! $export ) {
            return;
        }

        // Build style object from editor content
        $decoded = $this->infer_styles_from_content( $post_id );
        if ( empty( $decoded ) || ! is_array( $decoded ) ) return;

        $slug = sanitize_title( $post->post_name ?: $post->post_title );
        if ( '' === $slug ) {
            $slug = 'style-' . $post_id;
        }

        $blocktypes = get_post_meta( $post_id, self::META_BLOCKTYPES, true );
        if ( ! is_array( $blocktypes ) || empty( $blocktypes ) ) {
            $blocktypes = [ 'core/group', 'core/columns', 'core/column', 'core/cover' ];
        }

        $obj = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'slug' => $slug,
            'title' => get_the_title( $post_id ),
            'blockTypes' => array_values( array_filter( array_map( 'strval', $blocktypes ) ) ),
        ];
        if ( isset( $decoded['styles'] ) && is_array( $decoded['styles'] ) ) {
            $obj['styles'] = $decoded['styles'];
        }

        $target_dir = $this->get_target_dir();
        if ( ! wp_mkdir_p( $target_dir ) ) {
            set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => __( 'Impossible de créer le dossier de destination pour l\'export.', 'up' ) ], 30 );
            return;
        }

        $file = trailingslashit( $target_dir ) . $slug . '.json';
        $json = wp_json_encode( $obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        $bytes = @file_put_contents( $file, $json );
        if ( false === $bytes ) {
            set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => sprintf( __( 'Échec de l\'écriture du fichier: %s', 'up' ), esc_html( $file ) ) ], 30 );
        } else {
            $root = defined( 'ABSPATH' ) ? ABSPATH : '';
            $display = $root ? str_replace( $root, '', $file ) : $file;
            set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => sprintf( __( 'Style exporté vers: %s', 'up' ), esc_html( $display ) ) ], 30 );
        }
    }

    private function get_target_dir() {
        $theme_dir = get_stylesheet_directory(); // active child/main theme
        return trailingslashit( $theme_dir ) . 'styles/sections';
    }

    public function maybe_render_admin_notice() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $post_id ) return;
        if ( $screen && $screen->base !== 'post' ) return;
        $notice = get_transient( 'up_ss_notice_' . $post_id );
        if ( ! $notice ) return;
        delete_transient( 'up_ss_notice_' . $post_id );
        $class = $notice['type'] === 'success' ? 'notice notice-success' : 'notice notice-error';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
    }
}

new Up_Section_Styles_Plugin();
