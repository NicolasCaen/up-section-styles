<?php
/**
 * Plugin Name: Up Section Styles
 * Description: Registers the Section Style custom post type.
 * Version: 1.1.0
 * Author: NG1 / Up
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'init', function () {
    $labels = [
        'name'               => __( 'Section Styles', 'up' ),
        'singular_name'      => __( 'Section Style', 'up' ),
        'menu_name'          => __( 'Section Styles', 'up' ),
        'name_admin_bar'     => __( 'Section Style', 'up' ),
        'add_new'            => __( 'Add New', 'up' ),
        'add_new_item'       => __( 'Add New Section Style', 'up' ),
        'new_item'           => __( 'New Section Style', 'up' ),
        'edit_item'          => __( 'Edit Section Style', 'up' ),
        'view_item'          => __( 'View Section Style', 'up' ),
        'all_items'          => __( 'All Section Styles', 'up' ),
        'search_items'       => __( 'Search Section Styles', 'up' ),
        'parent_item_colon'  => __( 'Parent Section Styles:', 'up' ),
        'not_found'          => __( 'No Section Styles found.', 'up' ),
        'not_found_in_trash' => __( 'No Section Styles found in Trash.', 'up' ),
    ];

    register_post_type( 'section_style', [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_position'      => 22,
        'menu_icon'          => 'dashicons-art',
        'supports'           => [ 'title', 'editor' ],
        'has_archive'        => false,
        'rewrite'            => false,
        'show_in_rest'       => true,
        'capability_type'    => 'post',
    ] );
} );

// Full features
class Up_Section_Styles_Plugin_Restore {
    const CPT = 'section_style';
    const META_EXPORT = '_up_section_style_export';
    const META_BLOCKTYPES = '_up_section_style_blocktypes';
    const META_BLOCK_MODE = '_up_section_style_block_mode'; // legacy: 'multiple' | 'single'
    const META_SINGLE_BLOCK = '_up_section_style_single_block'; // e.g. 'core/paragraph'
    const META_EXPORT_TARGET = '_up_section_style_export_target'; // 'sections' | 'blocks' | 'block_type' | 'theme_json'
    const META_DEBUG = '_up_section_style_debug'; // boolean
    const META_EXTRACT_MODE = '_up_section_style_extract_mode'; // 'section' | 'block'

    public function __construct() {
        add_action( 'init', [ $this, 'register_meta' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_' . self::CPT, [ $this, 'save_meta_box' ], 10, 3 );
        add_action( 'save_post_' . self::CPT, [ $this, 'maybe_write_style_file' ], 20, 3 );
        add_action( 'admin_notices', [ $this, 'admin_notice' ] );
    }

    public function register_meta() {
        register_post_meta( self::CPT, self::META_EXPORT, [ 'type' => 'boolean', 'single' => true, 'show_in_rest' => true, 'default' => false ] );
        register_post_meta( self::CPT, self::META_BLOCKTYPES, [ 'type' => 'array', 'single' => true, 'show_in_rest' => true, 'default' => [ 'core/group', 'core/columns', 'core/column', 'core/cover' ] ] );
        register_post_meta( self::CPT, self::META_BLOCK_MODE, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => 'multiple' ] ); // legacy
        register_post_meta( self::CPT, self::META_SINGLE_BLOCK, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '' ] );
        register_post_meta( self::CPT, self::META_EXPORT_TARGET, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => 'sections' ] );
        register_post_meta( self::CPT, self::META_DEBUG, [ 'type' => 'boolean', 'single' => true, 'show_in_rest' => true, 'default' => false ] );
        register_post_meta( self::CPT, self::META_EXTRACT_MODE, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => 'section' ] );
    }

    public function add_meta_boxes() {
        add_meta_box( 'up_section_styles_box', __( 'Export de style', 'up' ), [ $this, 'render_meta_box' ], self::CPT, 'side', 'default' );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'up_ss_save', 'up_ss_nonce' );
        $export = (bool) get_post_meta( $post->ID, self::META_EXPORT, true );
        // legacy block mode retained only for fallback
        $block_mode = get_post_meta( $post->ID, self::META_BLOCK_MODE, true );
        if ( ! in_array( $block_mode, [ 'single', 'multiple' ], true ) ) { $block_mode = 'multiple'; }
        $single_block = (string) get_post_meta( $post->ID, self::META_SINGLE_BLOCK, true );
        $single_block_norm = $this->normalize_block_type( $single_block );
        $export_target = get_post_meta( $post->ID, self::META_EXPORT_TARGET, true );
        if ( ! in_array( $export_target, [ 'sections', 'blocks', 'block_type', 'theme_json' ], true ) ) { $export_target = 'sections'; }
        $debug = (bool) get_post_meta( $post->ID, self::META_DEBUG, true );
        $extract_mode = get_post_meta( $post->ID, self::META_EXTRACT_MODE, true );
        if ( ! in_array( $extract_mode, [ 'section', 'block' ], true ) ) { $extract_mode = 'section'; }
        $single_block_valid = true;
        if ( ! empty( $single_block_norm ) ) {
            $single_block_valid = $this->is_block_type_registered( $single_block_norm );
        }
        $blocktypes = get_post_meta( $post->ID, self::META_BLOCKTYPES, true );
        if ( ! is_array( $blocktypes ) || empty( $blocktypes ) ) { $blocktypes = [ 'core/group', 'core/columns', 'core/column', 'core/cover' ]; }
        $blocktypes_str = implode( ', ', array_map( 'sanitize_text_field', $blocktypes ) );

        echo '<p><label><input type="checkbox" name="up_ss_export" value="1" ' . checked( $export, true, false ) . ' /> ' . esc_html__( 'Exporter en fichier du thème', 'up' ) . '</label></p>';

        echo '<p><label>' . esc_html__( 'Cible d\'export', 'up' ) . '<br/>';
        echo '<select name="up_ss_export_target" class="widefat" id="up-ss-export-target">';
        $targets = [
            'sections'   => __( 'Section (styles/sections)', 'up' ),
            'block_type' => __( 'Block spécifique (styles/blocks/<type>)', 'up' ),
            'blocks'     => __( 'Blocks multiples (styles/blocks)', 'up' ),
            'theme_json' => __( 'Thème (écrire dans theme.json)', 'up' ),
        ];
        foreach ( $targets as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $export_target, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</label></p>';

        echo '<p><label>' . esc_html__( 'Mode d\'extraction', 'up' ) . '<br/>';
        echo '<select name="up_ss_extract_mode" class="widefat" id="up-ss-extract-mode">';
        echo '<option value="section" ' . selected( $extract_mode, 'section', false ) . '>' . esc_html__( 'Section (prend les blocs intérieurs)', 'up' ) . '</option>';
        echo '<option value="block" ' . selected( $extract_mode, 'block', false ) . '>' . esc_html__( 'Block (prend les attributs du block)', 'up' ) . '</option>';
        echo '</select>';
        echo '</label></p>';

        // Single block input shows when export target is block_type or theme_json
        echo '<p id="up-ss-single-block-wrap" style="' . ( in_array( $export_target, [ 'block_type', 'theme_json' ], true ) ? '' : 'display:none;' ) . '">';
        echo '<label>' . esc_html__( 'Type de block (sélectionner dans la liste)', 'up' ) . '<br/>';
        echo '<input type="text" class="widefat" name="up_ss_single_block" list="up-ss-block-list" value="' . esc_attr( $single_block ) . '" placeholder="core/paragraph" />';
        echo '</label>';
        $registered_blocks = [];
        if ( class_exists( 'WP_Block_Type_Registry' ) ) {
            $reg = WP_Block_Type_Registry::get_instance();
            if ( method_exists( $reg, 'get_all_registered' ) ) {
                $all = $reg->get_all_registered();
                if ( is_array( $all ) ) { foreach ( $all as $bn => $bt ) { $registered_blocks[] = $bn; } }
            }
        }
        if ( ! empty( $registered_blocks ) ) {
            sort( $registered_blocks );
            echo '<datalist id="up-ss-block-list">';
            foreach ( $registered_blocks as $bn ) { echo '<option value="' . esc_attr( $bn ) . '"></option>'; }
            echo '</datalist>';
        }
        if ( $block_mode === 'single' && $single_block && ! $single_block_valid ) {
            echo '<span style="color:#b32d2e;display:block;margin-top:4px;">' . esc_html__( 'Attention: ce type de block n\'existe pas. Vérifiez l\'orthographe (ex: core/paragraph).', 'up' ) . '</span>';
        }
        echo '</p>';

        // Multiple block types UI shows when export target is blocks (multiples)
        echo '<div id="up-ss-multiple-blocks-wrap" style="' . ( $export_target === 'blocks' ? '' : 'display:none;' ) . '">';
        echo '<label>' . esc_html__( 'Ajouter des types de block', 'up' ) . '</label>';
        echo '<div style="display:flex; gap:6px; margin:6px 0;">';
        echo '<input type="text" id="up-ss-add-block" list="up-ss-block-list" class="regular-text" placeholder="core/paragraph" />';
        echo '<button type="button" class="button" id="up-ss-add-btn">' . esc_html__( 'Ajouter', 'up' ) . '</button>';
        echo '</div>';
        echo '<input type="hidden" name="up_ss_blocktypes" id="up-ss-blocktypes" value="' . esc_attr( $blocktypes_str ) . '" />';
        echo '<div id="up-ss-tags" style="display:flex;flex-wrap:wrap;gap:6px;">';
        foreach ( $blocktypes as $bt ) {
            $bt_esc = esc_html( $bt );
            echo '<span class="tag" data-bt="' . esc_attr( $bt ) . '" style="background:#f0f0f1;border:1px solid #dcdcde;padding:2px 6px;border-radius:3px;display:inline-flex;align-items:center;gap:6px;">' . $bt_esc . ' <a href="#" class="up-ss-remove" aria-label="remove" style="text-decoration:none;">×</a></span>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Cliquez sur Ajouter pour insérer un type. Cliquez sur × pour le retirer.', 'up' ) . '</p>';
        echo '</div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){
            var input = document.getElementById("up-ss-add-block");
            var addBtn = document.getElementById("up-ss-add-btn");
            var hidden = document.getElementById("up-ss-blocktypes");
            var tags = document.getElementById("up-ss-tags");
            function getList(){ var v = hidden.value.trim(); if(!v) return []; return v.split(",").map(function(s){return s.trim();}).filter(Boolean); }
            function setList(arr){ hidden.value = arr.join(", "); }
            function addTag(val){
                val = (val||"").trim(); if(!val) return; var list = getList();
                if(list.indexOf(val) !== -1) { input.value=""; return; }
                list.push(val); setList(list);
                var span = document.createElement("span");
                span.className = "tag"; span.dataset.bt = val;
                span.style.cssText = "background:#f0f0f1;border:1px solid #dcdcde;padding:2px 6px;border-radius:3px;display:inline-flex;align-items:center;gap:6px;";
                span.innerHTML = val + " <a href=\'#\' class=\"up-ss-remove\" aria-label=\"remove\" style=\"text-decoration:none;\">×</a>";
                tags.appendChild(span); input.value="";
            }
            function removeTag(val){ var list = getList().filter(function(s){ return s!==val; }); setList(list); }
            addBtn && addBtn.addEventListener("click", function(e){ e.preventDefault(); addTag(input.value); });
            input && input.addEventListener("keydown", function(e){ if(e.key==="Enter"){ e.preventDefault(); addTag(input.value); }});
            tags && tags.addEventListener("click", function(e){ var a=e.target.closest("a.up-ss-remove"); if(!a) return; e.preventDefault(); var span=a.closest("span.tag"); if(!span) return; var val=span.dataset.bt||""; span.remove(); removeTag(val); });
        });</script>';

        // Insert H1–H4 + paragraph + buttons (neutral: no styles on group), replacing content
        echo '<p><button type="button" class="button" id="up-ss-insert-typography">' . esc_html__( 'Insérer modèle (H1–H4 + Texte + Bouton)', 'up' ) . '</button></p>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){
            var btn=document.getElementById("up-ss-insert-typography"); if(!btn) return;
            btn.addEventListener("click", function(e){ e.preventDefault();
                if(!(window.wp && wp.blocks && wp.data && wp.data.dispatch)) { alert("Éditeur non disponible."); return; }
                try {
                    var h1 = wp.blocks.createBlock("core/heading", { level: 1, content: "Titre de section" });
                    var h2 = wp.blocks.createBlock("core/heading", { level: 2, content: "Titre de section H2" });
                    var h3 = wp.blocks.createBlock("core/heading", { level: 3, content: "Titre de section H3" });
                    var h4 = wp.blocks.createBlock("core/heading", { level: 4, content: "Titre de section H4" });
                    var paragraph = wp.blocks.createBlock("core/paragraph", { content: "Un paragraphe avec un <a href=\\"/page-d-exemple/\\">lien</a>." });
                    var button = wp.blocks.createBlock("core/button", { text: "En savoir plus", url: "#" });
                    var buttons = wp.blocks.createBlock("core/buttons", {}, [ button ]);
                    var group = wp.blocks.createBlock("core/group", {}, [ h1, h2, h3, h4, paragraph, buttons ]);
                    wp.data.dispatch("core/editor").resetBlocks([ group ]);
                } catch(err) {
                    console.error(err);
                    alert("Impossible d\'insérer le modèle.");
                }
            });
        });</script>';

        echo '<p class="description">' . esc_html__( 'Les styles seront déduits automatiquement à partir des blocs du contenu.', 'up' ) . '</p>';
        // Toggle UI by export target
        echo '<script>document.addEventListener("DOMContentLoaded",function(){
            var target=document.getElementById("up-ss-export-target");
            var extract=document.getElementById("up-ss-extract-mode");
            var sWrap=document.getElementById("up-ss-single-block-wrap");
            var mWrap=document.getElementById("up-ss-multiple-blocks-wrap");
            function sync(){
                var v=target.value; var em=extract.value;
                var showSingle = (v==="block_type") || (v==="theme_json" && em!=="section");
                sWrap.style.display = showSingle?"":"none";
                mWrap.style.display = (v==="blocks")?"":"none";
            }
            target.addEventListener("change",sync); extract.addEventListener("change",sync); sync();
        });</script>';

        echo '<hr/>';
        echo '<p><label><input type="checkbox" name="up_ss_debug" value="1" ' . checked( $debug, true, false ) . ' /> ' . esc_html__( 'Mode debug (affiche des détails lors de l\'export)', 'up' ) . '</label></p>';

        // Insert neutral section template button
        echo '<hr/>';
        echo '<p><button type="button" class="button button-secondary" id="up-ss-insert-skeleton">' . esc_html__( 'Insérer un modèle neutre (Titre + Paragraphe + Bouton)', 'up' ) . '</button></p>';
        echo '<p class="description">' . esc_html__( 'Ajoute dans le contenu: un titre, un paragraphe avec un lien et un bouton.', 'up' ) . '</p>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){
            var btn=document.getElementById("up-ss-insert-skeleton"); if(!btn) return;
            btn.addEventListener("click", function(e){ e.preventDefault();
                if(!(window.wp && wp.blocks && wp.data && wp.data.dispatch)) { alert("Éditeur non disponible."); return; }
                try {
                    var heading = wp.blocks.createBlock("core/heading", { level: 2, content: "Titre de section" });
                    var paraHtml = "Un paragraphe avec un <a href=\\"/page-d-exemple/\\">lien</a>.";
                    var paragraph = wp.blocks.createBlock("core/paragraph", { content: paraHtml });
                    var button = wp.blocks.createBlock("core/button", { text: "En savoir plus", url: "#" });
                    var buttons = wp.blocks.createBlock("core/buttons", {}, [ button ]);
                    var group = wp.blocks.createBlock("core/group", {}, [ heading, paragraph, buttons ]);
                    // Replace the entire content with the group template
                    wp.data.dispatch("core/editor").resetBlocks([ group ]);
                } catch(err) {
                    console.error(err);
                    alert("Impossible d\'insérer le modèle.");
                }
            });
        });</script>';
    }

    public function save_meta_box( $post_id, $post, $update ) {
        if ( ! isset( $_POST['up_ss_nonce'] ) || ! wp_verify_nonce( $_POST['up_ss_nonce'], 'up_ss_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $export = isset( $_POST['up_ss_export'] ) ? (bool) $_POST['up_ss_export'] : false;
        $export_target = isset( $_POST['up_ss_export_target'] ) ? sanitize_text_field( wp_unslash( $_POST['up_ss_export_target'] ) ) : 'sections';
        if ( ! in_array( $export_target, [ 'sections', 'blocks', 'block_type', 'theme_json' ], true ) ) { $export_target = 'sections'; }
        $block_mode = isset( $_POST['up_ss_block_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['up_ss_block_mode'] ) ) : 'multiple'; // legacy
        if ( ! in_array( $block_mode, [ 'single', 'multiple' ], true ) ) { $block_mode = 'multiple'; }
        $single_block = isset( $_POST['up_ss_single_block'] ) ? sanitize_text_field( wp_unslash( $_POST['up_ss_single_block'] ) ) : '';
        $debug = isset( $_POST['up_ss_debug'] ) ? (bool) $_POST['up_ss_debug'] : false;
        $extract_mode = isset( $_POST['up_ss_extract_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['up_ss_extract_mode'] ) ) : 'section';
        if ( ! in_array( $extract_mode, [ 'section', 'block' ], true ) ) { $extract_mode = 'section'; }
        $blocktypes_str = isset( $_POST['up_ss_blocktypes'] ) ? (string) wp_unslash( $_POST['up_ss_blocktypes'] ) : '';
        $blocktypes = array_filter( array_map( 'trim', explode( ',', $blocktypes_str ) ) );
        if ( empty( $blocktypes ) ) { $blocktypes = [ 'core/group', 'core/columns', 'core/column', 'core/cover' ]; }

        update_post_meta( $post_id, self::META_EXPORT, $export );
        update_post_meta( $post_id, self::META_BLOCKTYPES, $blocktypes );
        update_post_meta( $post_id, self::META_BLOCK_MODE, $block_mode );
        update_post_meta( $post_id, self::META_SINGLE_BLOCK, $single_block );
        update_post_meta( $post_id, self::META_EXPORT_TARGET, $export_target );
        update_post_meta( $post_id, self::META_DEBUG, $debug );
        update_post_meta( $post_id, self::META_EXTRACT_MODE, $extract_mode );
    }

    public function maybe_write_style_file( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $export = (bool) get_post_meta( $post_id, self::META_EXPORT, true );
        if ( ! $export ) return;

        $export_target = get_post_meta( $post_id, self::META_EXPORT_TARGET, true );
        if ( ! in_array( $export_target, [ 'sections', 'blocks', 'block_type', 'theme_json' ], true ) ) { $export_target = 'sections'; }
        $block_mode = get_post_meta( $post_id, self::META_BLOCK_MODE, true ); // legacy
        if ( ! in_array( $block_mode, [ 'single', 'multiple' ], true ) ) { $block_mode = 'multiple'; }
        $single_block = (string) get_post_meta( $post_id, self::META_SINGLE_BLOCK, true );
        $single_block = $this->normalize_block_type( $single_block );
        $extract_mode = get_post_meta( $post_id, self::META_EXTRACT_MODE, true );
        if ( ! in_array( $extract_mode, [ 'section', 'block' ], true ) ) { $extract_mode = 'section'; }
        $debug = (bool) get_post_meta( $post_id, self::META_DEBUG, true );
        // Load selected blocktypes early (for multiples)
        $blocktypes = get_post_meta( $post_id, self::META_BLOCKTYPES, true );
        if ( ! is_array( $blocktypes ) || empty( $blocktypes ) ) { $blocktypes = [ 'core/group', 'core/columns', 'core/column', 'core/cover' ]; }

        // Force single behavior when target is block_type
        $is_single = ( $export_target === 'block_type' || $export_target === 'theme_json' );
        $target_block = $is_single ? $single_block : '';

        // Debug: announce start
        if ( $debug ) {
            $meta_preview = [
                'target' => $export_target,
                'extract' => $extract_mode,
                'is_single' => $is_single,
                'single_block' => $target_block,
            ];
            set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => sprintf( __( 'Export démarré. Meta: %s', 'up' ), wp_json_encode( $meta_preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ], 30 );
        }

        // build styles
        $decoded = null;
        if ( $is_single ) {
            if ( $extract_mode === 'block' ) {
                if ( empty( $target_block ) ) {
                    set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => __( 'Sélectionnez un type de block pour l\'export "Block spécifique".', 'up' ) ], 30 );
                    return;
                }
                if ( ! $this->is_block_type_registered( $target_block ) ) {
                    set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => sprintf( __( 'Type de block inconnu: %s', 'up' ), esc_html( $single_block ) ) ], 30 );
                    return;
                }
                $decoded = [ 'styles' => $this->infer_styles_for_single_block( $post_id, $target_block ) ];
            } else {
                // Section extraction (theme_json + section): do not require target_block
                $decoded = [ 'styles' => $this->infer_styles_for_section( $post_id ) ];
            }
        } else {
            // multiple blocks target
            if ( $extract_mode === 'section' ) {
                $decoded = [ 'styles' => $this->infer_styles_for_section( $post_id ) ];
            } else {
                // Extract attributes from each selected block type, merge styles (first value wins)
                $merged = [];
                foreach ( $blocktypes as $bt ) {
                    $bt = $this->normalize_block_type( (string) $bt );
                    if ( ! $this->is_block_type_registered( $bt ) ) { continue; }
                    $st = $this->infer_styles_for_single_block( $post_id, $bt );
                    if ( is_array( $st ) && ! empty( $st ) ) {
                        $merged = $this->merge_styles_preferring_first( $merged, $st );
                    }
                }
                $decoded = [ 'styles' => $merged ];
            }
        }
        if ( empty( $decoded ) || ! is_array( $decoded ) ) {
            set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => __( 'Aucun style détecté à exporter.', 'up' ) ], 30 );
            return;
        }

        $slug = sanitize_title( get_post_field( 'post_name', $post_id ) ?: get_the_title( $post_id ) );
        if ( '' === $slug ) { $slug = 'section-style-' . $post_id; }

        if ( $export_target === 'theme_json' ) {
            if ( $extract_mode === 'block' ) {
                // Single block defaults under styles.blocks[<type>]
                if ( ! $target_block ) {
                    set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => __( 'Pour écrire dans theme.json, sélectionnez le mode "Un seul type de block" et renseignez le type.', 'up' ) ], 30 );
                    return;
                }
                $styles_to_write = isset( $decoded['styles'] ) ? $decoded['styles'] : [];
                $styles_to_write = $this->normalize_styles_for_single_block( $target_block, $styles_to_write );
                $ok = $this->write_to_theme_json_block_defaults( $target_block, $styles_to_write );
                if ( ! $ok ) {
                    set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => __( 'Échec de la mise à jour de theme.json.', 'up' ) ], 30 );
                } else {
                    $debug = (bool) get_post_meta( $post_id, self::META_DEBUG, true );
                    if ( $debug ) {
                        $preview = wp_json_encode( [ 'block' => $target_block, 'styles_keys' => array_keys( $styles_to_write ) ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                        set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => sprintf( __( 'theme.json mis à jour pour "%s". Aperçu: %s', 'up' ), esc_html( $target_block ), $preview ) ], 30 );
                    } else {
                        set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => __( 'theme.json mis à jour pour le type de block.', 'up' ) ], 30 );
                    }
                }
                return;
            } else {
                // Section extraction → write root styles to styles.*, and inner blocks to styles.blocks
                $root_styles = isset( $decoded['styles'] ) && is_array( $decoded['styles'] ) ? $decoded['styles'] : [];
                // Collect per-block styles from inner blocks (only under the root group)
                $content = get_post_field( 'post_content', $post_id );
                $tree = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : [];
                $root = isset( $tree[0] ) ? $tree[0] : null;
                if ( is_array( $root ) && isset( $root['blockName'] ) && $root['blockName'] !== 'core/group' ) {
                    foreach ( $tree as $b ) { if ( isset( $b['blockName'] ) && $b['blockName'] === 'core/group' ) { $root = $b; break; } }
                }
                $per_block = [];
                $collect = function( $nodes ) use ( &$collect, &$per_block ) {
                    foreach ( $nodes as $b ) {
                        if ( ! is_array( $b ) || empty( $b['blockName'] ) ) continue;
                        $name = $b['blockName'];
                        // Skip writing defaults for core/group from section mode; those belong to root styles
                        if ( $name === 'core/group' ) {
                            if ( ! empty( $b['innerBlocks'] ) ) { $collect( $b['innerBlocks'] ); }
                            continue;
                        }
                        if ( ! isset( $per_block[ $name ] ) ) { $per_block[ $name ] = true; }
                        if ( ! empty( $b['innerBlocks'] ) ) { $collect( $b['innerBlocks'] ); }
                    }
                };
                $inner = ( is_array( $root ) && isset( $root['innerBlocks'] ) ) ? $root['innerBlocks'] : [];
                $collect( $inner );
                // Now actually infer styles per discovered block once, using post-level walker
                foreach ( array_keys( $per_block ) as $bn ) {
                    $st = $this->infer_styles_for_single_block( $post_id, $bn );
                    if ( is_array( $st ) && ! empty( $st ) ) {
                        // Never persist 'elements' at block level in section->theme export
                        if ( isset( $st['elements'] ) ) { unset( $st['elements'] ); }
                        // If nothing remains after stripping, skip
                        if ( empty( $st ) ) { unset( $per_block[ $bn ] ); continue; }
                        $per_block[ $bn ] = $st;
                    } else { unset( $per_block[ $bn ] ); }
                }
                // If nothing to write, inform and stop early
                if ( empty( $root_styles ) && empty( $per_block ) ) {
                    set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'warning', 'msg' => __( 'Aucun style de section ni de sous-bloc détecté pour theme.json.', 'up' ) ], 30 );
                    return;
                }
                $ok = $this->write_to_theme_json_section_defaults( $root_styles, $per_block );
                if ( ! $ok ) {
                    set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'error', 'msg' => __( 'Échec de la mise à jour de theme.json (section).', 'up' ) ], 30 );
                } else {
                    $debug = (bool) get_post_meta( $post_id, self::META_DEBUG, true );
                    if ( $debug ) {
                        $preview = wp_json_encode( [ 'styles_keys' => array_keys( $root_styles ), 'blocks' => array_keys( $per_block ) ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                        set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => sprintf( __( 'theme.json (section) mis à jour. Aperçu: %s', 'up' ), $preview ) ], 30 );
                    } else {
                        set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => __( 'theme.json mis à jour (section).', 'up' ) ], 30 );
                    }
                }
                return;
            }
        }

        // write variation file
        $effective_blocks = [];
        if ( $is_single && ! empty( $target_block ) ) {
            $effective_blocks = [ $target_block ];
        } else {
            $effective_blocks = array_values( array_filter( array_map( 'strval', $blocktypes ) ) );
        }

        $obj = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'slug' => $slug,
            'title' => get_the_title( $post_id ),
            'blockTypes' => $effective_blocks,
        ];
        if ( isset( $decoded['styles'] ) && is_array( $decoded['styles'] ) ) {
            $styles_normalized = $decoded['styles'];
            if ( $is_single && ! empty( $target_block ) ) {
                $styles_normalized = $this->normalize_styles_for_single_block( $target_block, $styles_normalized );
            }
            $obj['styles'] = $styles_normalized;
        } else {
            $obj['styles'] = new stdClass();
        }

        $target_dir = $this->get_target_dir( $export_target, $target_block );
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
            $debug = (bool) get_post_meta( $post_id, self::META_DEBUG, true );
            if ( $debug ) {
                $preview = wp_json_encode( [ 'target' => $export_target, 'block_mode' => $block_mode, 'single_block' => $single_block, 'file' => $display, 'blockTypes' => $effective_blocks, 'style_keys' => isset( $obj['styles'] ) && is_array( $obj['styles'] ) ? array_keys( $obj['styles'] ) : [] ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => sprintf( __( 'Style exporté vers: %s — Debug: %s', 'up' ), esc_html( $display ), $preview ) ], 30 );
            } else {
                set_transient( 'up_ss_notice_' . $post_id, [ 'type' => 'success', 'msg' => sprintf( __( 'Style exporté vers: %s', 'up' ), esc_html( $display ) ) ], 30 );
            }
        }
    }

    public function admin_notice() {
        if ( ! is_admin() ) return;
        global $pagenow;
        if ( $pagenow !== 'post.php' && $pagenow !== 'post-new.php' ) return;
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $post_id ) return;
        $key = 'up_ss_notice_' . $post_id;
        $notice = get_transient( $key );
        if ( $notice && is_array( $notice ) && isset( $notice['type'], $notice['msg'] ) ) {
            $class = $notice['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . wp_kses_post( $notice['msg'] ) . '</p></div>';
            delete_transient( $key );
        }
    }

    private function get_target_dir( $export_target = 'sections', $single_block = '' ) {
        $theme_dir = get_stylesheet_directory();
        $base = trailingslashit( $theme_dir ) . 'styles/';
        if ( $export_target === 'blocks' ) return $base . 'blocks';
        if ( $export_target === 'block_type' ) {
            $slug = $single_block ? str_replace( '/', '-', sanitize_title( $single_block ) ) : 'block';
            return $base . 'blocks/' . $slug;
        }
        return $base . 'sections';
    }

    private function normalize_block_type( $block ) {
        $block = is_string( $block ) ? trim( $block ) : '';
        if ( $block === '' ) return $block;
        $map = [ 'core/paragraphe' => 'core/paragraph', 'core/titre' => 'core/heading' ];
        return isset( $map[ $block ] ) ? $map[ $block ] : $block;
    }

    private function is_block_type_registered( $block_name ) {
        if ( ! is_string( $block_name ) || $block_name === '' ) return false;
        if ( class_exists( 'WP_Block_Type_Registry' ) ) {
            $registry = WP_Block_Type_Registry::get_instance();
            if ( method_exists( $registry, 'is_registered' ) ) return $registry->is_registered( $block_name );
            if ( method_exists( $registry, 'get_registered' ) ) return null !== $registry->get_registered( $block_name );
        }
        return true; // do not block if registry unavailable
    }

    private function resolve_color_from_attrs( $attrs, $key ) {
        $val = null;
        if ( isset( $attrs[ $key . 'Color' ] ) ) {
            $slug = sanitize_title( $attrs[ $key . 'Color' ] );
            $val = 'var(--wp--preset--color--' . $slug . ')';
        } elseif ( isset( $attrs['style']['color'][ $key ] ) ) {
            $val = $this->normalize_color_value( $attrs['style']['color'][ $key ] );
        }
        return $val;
    }

    private function normalize_color_value( $val ) {
        if ( is_string( $val ) ) {
            if ( 0 === strpos( $val, 'var:preset|color|' ) ) {
                $slug = sanitize_title( substr( $val, strlen( 'var:preset|color|' ) ) );
                return 'var(--wp--preset--color--' . $slug . ')';
            }
            if ( 0 === strpos( $val, 'var(' ) ) return $val;
            if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $val ) ) return $val;
        }
        return $val;
    }

    private function normalize_spacing_value( $val ) {
        if ( is_string( $val ) && 0 === strpos( $val, 'var:preset|spacing|' ) ) {
            $slug = sanitize_title( substr( $val, strlen( 'var:preset|spacing|' ) ) );
            return 'var(--wp--preset--spacing--' . $slug . ')';
        }
        return $val;
    }

    private function infer_styles_for_single_block( $post_id, $block_type ) {
        $content = get_post_field( 'post_content', $post_id );
        $blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : [];
        $found = null;
        $walker = function( $nodes ) use ( &$walker, $block_type, &$found ) {
            foreach ( $nodes as $b ) {
                if ( ! is_array( $b ) ) continue;
                if ( isset( $b['blockName'] ) && $b['blockName'] === $block_type ) { $found = $b; return; }
                if ( ! empty( $b['innerBlocks'] ) ) $walker( $b['innerBlocks'] );
                if ( $found ) return;
            }
        };
        $walker( $blocks );
        if ( ! $found ) return [];
        $attrs = isset( $found['attrs'] ) ? $found['attrs'] : [];
        $styles = [];

        // Colors
        $bg = $this->resolve_color_from_attrs( $attrs, 'background' );
        $tx = $this->resolve_color_from_attrs( $attrs, 'text' );
        $gradient = null;
        if ( isset( $attrs['style']['color']['gradient'] ) ) { $gradient = $attrs['style']['color']['gradient']; }
        elseif ( isset( $attrs['gradient'] ) ) { $gslug = sanitize_title( $attrs['gradient'] ); $gradient = 'var(--wp--preset--gradient--' . $gslug . ')'; }
        if ( $bg || $tx || $gradient ) {
            $styles['color'] = [];
            if ( $bg ) $styles['color']['background'] = $bg;
            if ( $tx ) $styles['color']['text'] = $tx;
            if ( $gradient ) $styles['color']['gradient'] = $gradient;
        }

        // Border
        if ( isset( $attrs['style']['border'] ) && is_array( $attrs['style']['border'] ) ) {
            $b = $attrs['style']['border'];
            $out = [];
            if ( isset( $b['width'] ) ) $out['width'] = $b['width'];
            if ( isset( $b['radius'] ) ) $out['radius'] = $b['radius'];
            if ( isset( $b['color'] ) ) { $c = $this->normalize_color_value( $b['color'] ); if ( $c ) { $out['color'] = $c; } }
            if ( isset( $b['style'] ) && $b['style'] !== '' ) { $out['style'] = $b['style']; }
            elseif ( isset( $b['width'] ) && $b['width'] !== '' ) { $out['style'] = 'solid'; }
            if ( ! empty( $out ) ) $styles['border'] = $out;
        }

        // Spacing padding
        if ( isset( $attrs['style']['spacing']['padding'] ) && is_array( $attrs['style']['spacing']['padding'] ) ) {
            $pad = $attrs['style']['spacing']['padding'];
            $pout = [];
            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                if ( isset( $pad[ $side ] ) ) { $pout[ $side ] = $this->normalize_spacing_value( $pad[ $side ] ); }
            }
            if ( ! empty( $pout ) ) $styles['spacing']['padding'] = $pout;
        }
        // Spacing margin
        if ( isset( $attrs['style']['spacing']['margin'] ) && is_array( $attrs['style']['spacing']['margin'] ) ) {
            $mar = $attrs['style']['spacing']['margin'];
            $mout = [];
            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                if ( isset( $mar[ $side ] ) ) { $mout[ $side ] = $this->normalize_spacing_value( $mar[ $side ] ); }
            }
            if ( ! empty( $mout ) ) $styles['spacing']['margin'] = $mout;
        }
        // Spacing blockGap
        if ( isset( $attrs['style']['spacing']['blockGap'] ) && $attrs['style']['spacing']['blockGap'] !== '' ) {
            $styles['spacing']['blockGap'] = $this->normalize_spacing_value( $attrs['style']['spacing']['blockGap'] );
        }

        // Typography
        if ( isset( $attrs['fontSize'] ) && is_string( $attrs['fontSize'] ) && $attrs['fontSize'] !== '' ) {
            $slug = sanitize_title( $attrs['fontSize'] );
            $styles['typography']['fontSize'] = 'var(--wp--preset--font-size--' . $slug . ')';
        }
        if ( isset( $attrs['style']['typography'] ) && is_array( $attrs['style']['typography'] ) ) {
            $t = $attrs['style']['typography'];
            $map_keys = [ 'letterSpacing', 'lineHeight', 'textDecoration', 'writingMode', 'fontStyle', 'fontWeight', 'textTransform', 'textAlign' ];
            foreach ( $map_keys as $k ) { if ( isset( $t[ $k ] ) && $t[ $k ] !== '' ) { $styles['typography'][ $k ] = $t[ $k ]; } }
            if ( isset( $t['fontSize'] ) && $t['fontSize'] !== '' && ! isset( $styles['typography']['fontSize'] ) ) { $styles['typography']['fontSize'] = $t['fontSize']; }
        }

        // Layout
        if ( isset( $attrs['style']['layout'] ) && is_array( $attrs['style']['layout'] ) ) {
            $l = $attrs['style']['layout'];
            foreach ( [ 'justifyContent', 'alignItems', 'flexWrap' ] as $k ) { if ( isset( $l[ $k ] ) && $l[ $k ] !== '' ) { $styles['layout'][ $k ] = $l[ $k ]; } }
        }

        // Dimensions
        if ( isset( $attrs['style']['dimensions'] ) && is_array( $attrs['style']['dimensions'] ) ) {
            $d = $attrs['style']['dimensions'];
            if ( isset( $d['minHeight'] ) && $d['minHeight'] !== '' ) { $styles['dimensions']['minHeight'] = $d['minHeight']; }
            if ( isset( $d['aspectRatio'] ) && $d['aspectRatio'] !== '' ) { $styles['dimensions']['aspectRatio'] = $d['aspectRatio']; }
        }

        // Shadow / Outline
        if ( isset( $attrs['style']['shadow'] ) && $attrs['style']['shadow'] !== '' ) { $styles['shadow'] = $attrs['style']['shadow']; }
        if ( isset( $attrs['style']['outline'] ) && $attrs['style']['outline'] !== '' ) { $styles['outline'] = $attrs['style']['outline']; }

        // Elements -> link
        if ( isset( $attrs['style']['elements']['link']['color']['text'] ) ) {
            $l = $attrs['style']['elements']['link']['color']['text'];
            $val = $this->normalize_color_value( $l );
            if ( $val ) { $styles['elements']['link']['color']['text'] = $val; }
            $styles['elements']['link']['typography']['textDecoration'] = 'none';
        }

        return $styles;
    }

    private function normalize_styles_for_single_block( $block_type, $styles ) {
        // Special heading normalization: promote text color
        if ( $block_type === 'core/heading' ) {
            if ( isset( $styles['elements']['heading']['color']['text'] ) ) {
                $styles['color']['text'] = $styles['elements']['heading']['color']['text'];
                unset( $styles['elements']['heading']['color']['text'] );
                if ( empty( $styles['elements']['heading']['color'] ) ) unset( $styles['elements']['heading']['color'] );
                if ( empty( $styles['elements']['heading'] ) ) unset( $styles['elements']['heading'] );
            }
        }
        return $styles;
    }

    private function write_to_theme_json_block_defaults( $block_type, $styles ) {
        $theme_dir = get_stylesheet_directory();
        $file = trailingslashit( $theme_dir ) . 'theme.json';
        if ( ! file_exists( $file ) ) return false;
        $raw = file_get_contents( $file );
        if ( $raw === false ) return false;
        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) return false;
        if ( ! isset( $json['styles'] ) || ! is_array( $json['styles'] ) ) $json['styles'] = [];
        if ( ! isset( $json['styles']['blocks'] ) || ! is_array( $json['styles']['blocks'] ) ) $json['styles']['blocks'] = [];
        if ( ! isset( $json['styles']['blocks'][ $block_type ] ) || ! is_array( $json['styles']['blocks'][ $block_type ] ) ) $json['styles']['blocks'][ $block_type ] = [];
        $json['styles']['blocks'][ $block_type ] = array_replace_recursive( $json['styles']['blocks'][ $block_type ], $styles );
        $out = wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        return false !== @file_put_contents( $file, $out );
    }

    private function write_to_theme_json_section_defaults( $root_styles, $per_block_styles ) {
        $theme_dir = get_stylesheet_directory();
        $file = trailingslashit( $theme_dir ) . 'theme.json';
        if ( ! file_exists( $file ) ) return false;
        $raw = file_get_contents( $file );
        if ( $raw === false ) return false;
        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) return false;
        if ( ! isset( $json['styles'] ) || ! is_array( $json['styles'] ) ) $json['styles'] = [];
        // Deep merge root styles (new values override existing ones)
        $json['styles'] = $this->deep_merge_overlay( $json['styles'], is_array( $root_styles ) ? $root_styles : [] );
        // Merge per-block styles
        if ( ! isset( $json['styles']['blocks'] ) || ! is_array( $json['styles']['blocks'] ) ) $json['styles']['blocks'] = [];
        if ( is_array( $per_block_styles ) ) {
            foreach ( $per_block_styles as $bn => $st ) {
                if ( ! isset( $json['styles']['blocks'][ $bn ] ) || ! is_array( $json['styles']['blocks'][ $bn ] ) ) $json['styles']['blocks'][ $bn ] = [];
                $json['styles']['blocks'][ $bn ] = $this->deep_merge_overlay( $json['styles']['blocks'][ $bn ], $st );
            }
        }
        $out = wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        return false !== @file_put_contents( $file, $out );
    }

    private function deep_merge_overlay( $base, $overlay ) {
        if ( ! is_array( $base ) ) $base = [];
        if ( ! is_array( $overlay ) ) return $base;
        foreach ( $overlay as $k => $v ) {
            if ( array_key_exists( $k, $base ) ) {
                if ( is_array( $base[$k] ) && is_array( $v ) ) {
                    $base[$k] = $this->deep_merge_overlay( $base[$k], $v );
                } else {
                    $base[$k] = $v; // overlay wins
                }
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private function merge_styles_preferring_first( $a, $b ) {
        if ( ! is_array( $a ) ) $a = [];
        if ( ! is_array( $b ) ) return $a;
        foreach ( $b as $k => $v ) {
            if ( array_key_exists( $k, $a ) ) {
                if ( is_array( $a[ $k ] ) && is_array( $v ) ) {
                    $a[ $k ] = $this->merge_styles_preferring_first( $a[ $k ], $v );
                } else {
                    // keep existing (first wins)
                }
            } else {
                $a[ $k ] = $v;
            }
        }
        return $a;
    }

    private function infer_styles_for_section( $post_id ) {
        $content = get_post_field( 'post_content', $post_id );
        $blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : [];
        if ( empty( $blocks ) ) return [];
        $root = $blocks[0];
        if ( ! is_array( $root ) || ! isset( $root['blockName'] ) ) return [];
        // Try to find top-level group; if first block is not a group, look for first group
        if ( $root['blockName'] !== 'core/group' ) {
            foreach ( $blocks as $b ) { if ( isset( $b['blockName'] ) && $b['blockName'] === 'core/group' ) { $root = $b; break; } }
        }
        $styles = [];
        $attrs = isset( $root['attrs'] ) ? $root['attrs'] : [];
        // Background/text/gradient from style or preset fields
        $bg = null; $tx = null; $gradient = null;
        if ( isset( $attrs['style']['color']['background'] ) ) { $bg = $this->normalize_color_value( $attrs['style']['color']['background'] ); }
        if ( ! $bg && isset( $attrs['backgroundColor'] ) ) { $bg = 'var(--wp--preset--color--' . sanitize_title( $attrs['backgroundColor'] ) . ')'; }
        if ( isset( $attrs['style']['color']['text'] ) ) { $tx = $this->normalize_color_value( $attrs['style']['color']['text'] ); }
        if ( ! $tx && isset( $attrs['textColor'] ) ) { $tx = 'var(--wp--preset--color--' . sanitize_title( $attrs['textColor'] ) . ')'; }
        if ( isset( $attrs['style']['color']['gradient'] ) ) { $gradient = $attrs['style']['color']['gradient']; }
        elseif ( isset( $attrs['gradient'] ) ) { $gslug = sanitize_title( $attrs['gradient'] ); $gradient = 'var(--wp--preset--gradient--' . $gslug . ')'; }
        if ( $bg || $tx || $gradient ) {
            $styles['color'] = [];
            if ( $bg ) $styles['color']['background'] = $bg;
            if ( $tx ) $styles['color']['text'] = $tx;
            if ( $gradient ) $styles['color']['gradient'] = $gradient;
        }
        // Elements from group style
        if ( isset( $attrs['style']['elements'] ) && is_array( $attrs['style']['elements'] ) ) {
            $els = $attrs['style']['elements'];
            // link color
            if ( isset( $els['link']['color']['text'] ) ) {
                $styles['elements']['link']['color']['text'] = $this->normalize_color_value( $els['link']['color']['text'] );
                $styles['elements']['link']['typography']['textDecoration'] = 'none';
            }
            // button colors
            if ( isset( $els['button']['color'] ) && is_array( $els['button']['color'] ) ) {
                if ( isset( $els['button']['color']['text'] ) ) {
                    $styles['elements']['button']['color']['text'] = $this->normalize_color_value( $els['button']['color']['text'] );
                }
                if ( isset( $els['button']['color']['background'] ) ) {
                    $styles['elements']['button']['color']['background'] = $this->normalize_color_value( $els['button']['color']['background'] );
                }
            }
            // headings h1..h6 colors
            foreach ( [ 'h1','h2','h3','h4','h5','h6' ] as $hx ) {
                if ( isset( $els[ $hx ]['color']['text'] ) ) {
                    $styles['elements'][ $hx ]['color']['text'] = $this->normalize_color_value( $els[ $hx ]['color']['text'] );
                }
            }
        }
        // Border (style/color/width/radius)
        if ( isset( $attrs['style']['border'] ) && is_array( $attrs['style']['border'] ) ) {
            $b = $attrs['style']['border'];
            $out = [];
            if ( isset( $b['width'] ) ) $out['width'] = $b['width'];
            if ( isset( $b['radius'] ) ) $out['radius'] = $b['radius'];
            if ( isset( $b['color'] ) ) { $c = $this->normalize_color_value( $b['color'] ); if ( $c ) { $out['color'] = $c; } }
            if ( isset( $b['style'] ) && $b['style'] !== '' ) { $out['style'] = $b['style']; }
            elseif ( isset( $b['width'] ) && $b['width'] !== '' ) { $out['style'] = 'solid'; }
            if ( ! empty( $out ) ) $styles['border'] = $out;
        }
        // Spacing padding/margin/blockGap
        if ( isset( $attrs['style']['spacing']['padding'] ) && is_array( $attrs['style']['spacing']['padding'] ) ) {
            $pad = $attrs['style']['spacing']['padding'];
            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                if ( isset( $pad[ $side ] ) ) { $styles['spacing']['padding'][ $side ] = $this->normalize_spacing_value( $pad[ $side ] ); }
            }
        }
        if ( isset( $attrs['style']['spacing']['margin'] ) && is_array( $attrs['style']['spacing']['margin'] ) ) {
            $mar = $attrs['style']['spacing']['margin'];
            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                if ( isset( $mar[ $side ] ) ) { $styles['spacing']['margin'][ $side ] = $this->normalize_spacing_value( $mar[ $side ] ); }
            }
        }
        if ( isset( $attrs['style']['spacing']['blockGap'] ) && $attrs['style']['spacing']['blockGap'] !== '' ) {
            $styles['spacing']['blockGap'] = $this->normalize_spacing_value( $attrs['style']['spacing']['blockGap'] );
        }
        // Typography on group
        if ( isset( $attrs['style']['typography'] ) && is_array( $attrs['style']['typography'] ) ) {
            $t = $attrs['style']['typography'];
            $map_keys = [ 'letterSpacing', 'lineHeight', 'textDecoration', 'writingMode', 'fontStyle', 'fontWeight', 'textTransform', 'textAlign' ];
            foreach ( $map_keys as $k ) { if ( isset( $t[ $k ] ) && $t[ $k ] !== '' ) { $styles['typography'][ $k ] = $t[ $k ]; } }
            if ( isset( $t['fontSize'] ) && $t['fontSize'] !== '' ) { $styles['typography']['fontSize'] = $t['fontSize']; }
        }
        // Layout (top-level attrs.layout)
        if ( isset( $attrs['layout'] ) && is_array( $attrs['layout'] ) ) {
            foreach ( [ 'justifyContent', 'alignItems', 'flexWrap' ] as $k ) { if ( isset( $attrs['layout'][ $k ] ) && $attrs['layout'][ $k ] !== '' ) { $styles['layout'][ $k ] = $attrs['layout'][ $k ]; } }
        }
        // Dimensions
        if ( isset( $attrs['style']['dimensions'] ) && is_array( $attrs['style']['dimensions'] ) ) {
            $d = $attrs['style']['dimensions'];
            if ( isset( $d['minHeight'] ) && $d['minHeight'] !== '' ) { $styles['dimensions']['minHeight'] = $d['minHeight']; }
            if ( isset( $d['aspectRatio'] ) && $d['aspectRatio'] !== '' ) { $styles['dimensions']['aspectRatio'] = $d['aspectRatio']; }
        }
        // Also look for first inner heading to set generic heading color
        $found_heading = null;
        $walker = function( $nodes ) use ( &$walker, &$found_heading ) {
            foreach ( $nodes as $b ) {
                if ( ! is_array( $b ) ) continue;
                if ( isset( $b['blockName'] ) && $b['blockName'] === 'core/heading' ) { $found_heading = $b; return; }
                if ( ! empty( $b['innerBlocks'] ) ) $walker( $b['innerBlocks'] );
                if ( $found_heading ) return;
            }
        };
        $inner = isset( $root['innerBlocks'] ) ? $root['innerBlocks'] : [];
        $walker( $inner );
        if ( $found_heading ) {
            $ha = isset( $found_heading['attrs'] ) ? $found_heading['attrs'] : [];
            $hcol = null;
            if ( isset( $ha['style']['color']['text'] ) ) { $hcol = $this->normalize_color_value( $ha['style']['color']['text'] ); }
            if ( ! $hcol && isset( $ha['textColor'] ) ) { $hcol = 'var(--wp--preset--color--' . sanitize_title( $ha['textColor'] ) . ')'; }
            if ( $hcol ) { $styles['elements']['heading']['color']['text'] = $hcol; }
        }
        return $styles;
    }
}

add_action( 'plugins_loaded', function() {
    new Up_Section_Styles_Plugin_Restore();
} );
