<?php
/**
 * CRM-only role, user and menu-access management.
 *
 * @package CRMBarghBazar
 */

defined( 'ABSPATH' ) || exit;

/**
 * One source of truth for CRM navigation. Additional sections can register
 * themselves with the filter when they are actually implemented.
 */
function crmbarghbazar_menu_registry() {
    return apply_filters(
        'crmbarghbazar_menu_registry',
        array(
            'attendance' => array(
                'label'      => 'حضور و غیاب',
                'admin_only' => true,
            ),
            'clock' => array(
                'label'          => 'ثبت ورود / خروج',
                'always_visible' => true,
            ),
        )
    );
}

function crmbarghbazar_can_view_section( $section ) {
    $items = crmbarghbazar_menu_registry();

    if ( ! isset( $items[ $section ] ) ) {
        return false;
    }
    if ( isset( $items[ $section ]['access_callback'] ) && is_callable( $items[ $section ]['access_callback'] ) ) {
        return (bool) call_user_func( $items[ $section ]['access_callback'] );
    }

    if ( ! empty( $items[ $section ]['admin_only'] ) ) {
        return current_user_can( 'manage_options' );
    }
    if ( ! empty( $items[ $section ]['always_visible'] ) ) {
        return is_user_logged_in();
    }

    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }

    // An individual user can optionally override their role's menu visibility.
    $user_id = get_current_user_id();
    if ( metadata_exists( 'user', $user_id, 'crmbarghbazar_menu_override' ) ) {
        $allowed = get_user_meta( $user_id, 'crmbarghbazar_menu_override', true );
        return is_array( $allowed ) && in_array( $section, $allowed, true );
    }

    return current_user_can( 'crm_menu_' . $section );
}

/** Sections to which a CRM role may be granted access (not admin-only pages). */
function crmbarghbazar_assignable_sections() {
    $available = array();
    foreach ( crmbarghbazar_menu_registry() as $slug => $item ) {
        if ( empty( $item['admin_only'] ) && empty( $item['always_visible'] ) && empty( $item['access_callback'] ) && ! empty( $item['label'] ) ) {
            $available[ $slug ] = $item['label'];
        }
    }
    return $available;
}

function crmbarghbazar_owned_roles() {
    $roles = get_option( 'crmbarghbazar_owned_roles', array() );
    return is_array( $roles ) ? array_values( array_filter( $roles, 'is_string' ) ) : array();
}

function crmbarghbazar_allowed_user_roles() {
    $allowed = array();
    if ( get_role( 'subscriber' ) ) {
        $allowed['subscriber'] = translate_user_role( wp_roles()->roles['subscriber']['name'] );
    }
    foreach ( crmbarghbazar_owned_roles() as $slug ) {
        $role = get_role( $slug );
        if ( $role && isset( wp_roles()->roles[ $slug ]['name'] ) ) {
            $allowed[ $slug ] = wp_roles()->roles[ $slug ]['name'];
        }
    }
    return $allowed;
}

function crmbarghbazar_update_role_sections( $slug, $requested ) {
    $role = get_role( $slug );
    if ( ! $role ) {
        return;
    }

    // Clear the old CRM-menu permissions, never other WordPress capabilities.
    foreach ( array_keys( $role->capabilities ) as $cap ) {
        if ( 0 === strpos( $cap, 'crm_menu_' ) ) {
            $role->remove_cap( $cap );
        }
    }

    foreach ( array_keys( crmbarghbazar_assignable_sections() ) as $section ) {
        if ( in_array( $section, $requested, true ) ) {
            $role->add_cap( 'crm_menu_' . $section );
        }
    }
}

/** Handle all writes via a checked, nonce-protected WordPress endpoint. */
function crmbarghbazar_handle_access_action() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Access denied.', 'crmbarghbazar' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'crmbarghbazar_access_action', 'crm_access_nonce' );

    $task = isset( $_POST['crm_task'] ) && is_string( $_POST['crm_task'] )
        ? sanitize_key( wp_unslash( $_POST['crm_task'] ) )
        : '';
    $role_slug = isset( $_POST['crm_role_slug'] ) && is_string( $_POST['crm_role_slug'] )
        ? sanitize_key( wp_unslash( $_POST['crm_role_slug'] ) )
        : '';
    $user_id = isset( $_POST['crm_user_id'] ) ? absint( $_POST['crm_user_id'] ) : 0;
    $role_tasks = array( 'create_role', 'edit_role', 'delete_role' );
    $tab = in_array( $task, $role_tasks, true ) ? 'roles' : 'users';
    $notice = 'invalid';

    if ( in_array( $task, $role_tasks, true ) ) {
        $owned = crmbarghbazar_owned_roles();
        $name = isset( $_POST['crm_role_name'] ) && is_string( $_POST['crm_role_name'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['crm_role_name'] ) ) )
            : '';
        $posted_sections = isset( $_POST['crm_permissions'] ) && is_array( $_POST['crm_permissions'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['crm_permissions'] ) )
            : array();

        if ( 'create_role' === $task && '' !== $name && strlen( $name ) <= 180 ) {
            // Opaque, stable internal role key; names may be in Persian.
            do {
                $role_slug = 'crm_' . strtolower( wp_generate_password( 12, false, false ) );
            } while ( get_role( $role_slug ) );

            if ( add_role( $role_slug, $name, array( 'read' => true ) ) ) {
                $owned[] = $role_slug;
                update_option( 'crmbarghbazar_owned_roles', array_values( array_unique( $owned ) ), false );
                crmbarghbazar_update_role_sections( $role_slug, $posted_sections );
                $notice = 'role_created';
            }
        } elseif ( 'edit_role' === $task && in_array( $role_slug, $owned, true ) && get_role( $role_slug ) && '' !== $name && strlen( $name ) <= 180 ) {
            $wp_roles = wp_roles();
            // Keep the slug and all assigned users unchanged when renaming.
            $wp_roles->roles[ $role_slug ]['name'] = $name;
            $wp_roles->role_names[ $role_slug ] = $name;
            if ( isset( $wp_roles->role_objects[ $role_slug ] ) ) {
                $wp_roles->role_objects[ $role_slug ]->name = $name;
            }
            update_option( $wp_roles->role_key, $wp_roles->roles );
            crmbarghbazar_update_role_sections( $role_slug, $posted_sections );
            $notice = 'role_updated';
        } elseif ( 'delete_role' === $task && in_array( $role_slug, $owned, true ) && get_role( $role_slug ) ) {
            // Never leave users stranded with a deleted role.
            $assigned = get_users( array( 'role' => $role_slug, 'number' => 1, 'fields' => 'ID' ) );
            if ( $assigned ) {
                $notice = 'role_in_use';
            } else {
                remove_role( $role_slug );
                update_option( 'crmbarghbazar_owned_roles', array_values( array_diff( $owned, array( $role_slug ) ) ), false );
                $notice = 'role_deleted';
            }
        }
    } elseif ( in_array( $task, array( 'create_user', 'edit_user', 'delete_user' ), true ) ) {
        $existing = $user_id ? get_userdata( $user_id ) : false;
        $protected = $existing && ( $user_id === get_current_user_id() || user_can( $existing, 'manage_options' ) );

        if ( 'delete_user' === $task && $existing && ! $protected ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            // Preserve authored content by reassigning it to the manager.
            $notice = wp_delete_user( $user_id, get_current_user_id() ) ? 'user_deleted' : 'invalid';
        } elseif ( in_array( $task, array( 'create_user', 'edit_user' ), true ) && ! $protected ) {
            $email = isset( $_POST['crm_email'] ) && is_string( $_POST['crm_email'] )
                ? sanitize_email( wp_unslash( $_POST['crm_email'] ) )
                : '';
            $first = isset( $_POST['crm_first_name'] ) && is_string( $_POST['crm_first_name'] )
                ? sanitize_text_field( wp_unslash( $_POST['crm_first_name'] ) )
                : '';
            $last = isset( $_POST['crm_last_name'] ) && is_string( $_POST['crm_last_name'] )
                ? sanitize_text_field( wp_unslash( $_POST['crm_last_name'] ) )
                : '';
            $selected_role = isset( $_POST['crm_user_role'] ) && is_string( $_POST['crm_user_role'] )
                ? sanitize_key( wp_unslash( $_POST['crm_user_role'] ) )
                : '';
            $password = isset( $_POST['crm_password'] ) && is_string( $_POST['crm_password'] )
                ? (string) wp_unslash( $_POST['crm_password'] )
                : '';
            $menu_mode = isset( $_POST['crm_menu_mode'] ) && is_string( $_POST['crm_menu_mode'] )
                ? sanitize_key( wp_unslash( $_POST['crm_menu_mode'] ) ) : 'role';
            $requested_menu = isset( $_POST['crm_user_menus'] ) && is_array( $_POST['crm_user_menus'] )
                ? array_map( 'sanitize_key', wp_unslash( $_POST['crm_user_menus'] ) ) : array();
            $safe_menu = array_values( array_intersect( $requested_menu, array_keys( crmbarghbazar_assignable_sections() ) ) );
            $duplicate_email_id = email_exists( $email );
            $email_ok = is_email( $email ) && ( ! $duplicate_email_id || (int) $duplicate_email_id === $user_id );
            $role_ok = array_key_exists( $selected_role, crmbarghbazar_allowed_user_roles() );
            $password_ok = '' === $password || strlen( $password ) >= 12;

            if ( ! $email_ok ) {
                $notice = 'email_invalid';
            } elseif ( ! $role_ok || ! $password_ok ) {
                $notice = 'invalid';
            } elseif ( 'create_user' === $task ) {
                $login = isset( $_POST['crm_login'] ) && is_string( $_POST['crm_login'] )
                    ? sanitize_user( wp_unslash( $_POST['crm_login'] ), true )
                    : '';
                if ( '' !== $login && validate_username( $login ) && ! username_exists( $login ) && strlen( $password ) >= 12 ) {
                    $created = wp_insert_user(
                        array(
                            'user_login'   => $login,
                            'user_pass'    => $password,
                            'user_email'   => $email,
                            'first_name'   => $first,
                            'last_name'    => $last,
                            'display_name' => trim( $first . ' ' . $last ) ?: $login,
                            'role'         => $selected_role,
                        )
                    );
                    if ( ! is_wp_error( $created ) ) {
                        if ( 'custom' === $menu_mode ) {
                            update_user_meta( $created, 'crmbarghbazar_menu_override', $safe_menu );
                        }
                        $notice = 'user_created';
                    }
                }
            } elseif ( 'edit_user' === $task && $existing ) {
                $changes = array(
                    'ID'           => $user_id,
                    'user_email'   => $email,
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'display_name' => trim( $first . ' ' . $last ) ?: $existing->user_login,
                    'role'         => $selected_role,
                );
                if ( '' !== $password ) {
                    $changes['user_pass'] = $password;
                }
                $changed = wp_update_user( $changes );
                if ( ! is_wp_error( $changed ) ) {
                    if ( 'custom' === $menu_mode ) {
                        update_user_meta( $user_id, 'crmbarghbazar_menu_override', $safe_menu );
                    } else {
                        delete_user_meta( $user_id, 'crmbarghbazar_menu_override' );
                    }
                    $notice = 'user_updated';
                }
            }
        }
    }

    wp_safe_redirect(
        add_query_arg(
            array( 'crm_section' => 'departments', 'tab' => $tab, 'crm_notice' => $notice ),
            home_url( '/main/' )
        )
    );
    exit;
}
add_action( 'admin_post_crmbarghbazar_access', 'crmbarghbazar_handle_access_action' );
