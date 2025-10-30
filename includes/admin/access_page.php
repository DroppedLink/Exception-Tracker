<?php

namespace ETracker\Admin;

use ETracker\Services\UserCapabilityManager;
use WP_User_Query;
use function add_action;
use function add_query_arg;
use function admin_url;
use function array_filter;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_current_blog_id;
use function get_current_user_id;
use function get_edit_user_link;
use function get_editable_roles;
use function in_array;
use function sanitize_text_field;
use function selected;
use function translate_user_role;
use function wp_die;
use function wp_enqueue_style;
use function wp_nonce_field;
use function wp_redirect;
use function wp_safe_redirect;
use function wp_unslash;
use function wp_verify_nonce;

class Access_Page
{
    private const PAGE_SLUG = 'etracker-access';
    private const NONCE_ACTION = 'etracker_access_action';
    private const PER_PAGE = 20;

    private UserCapabilityManager $capability_manager;
    private bool $hooks_registered = false;

    public function __construct(?UserCapabilityManager $manager = null)
    {
        $this->capability_manager = $manager ?? new UserCapabilityManager(ETRACKER_MANAGE_CAPABILITY);
    }

    public function register_hooks(): void
    {
        if ($this->hooks_registered) {
            return;
        }

        $this->maybe_handle_post();
        add_action('admin_init', [$this, 'maybe_handle_post']);
        $this->hooks_registered = true;
    }

    public function render(): void
    {
        if (! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'etracker'));
        }

        wp_enqueue_style('etracker-ui');

        $filters = $this->collect_filters();
        $query = $this->query_users($filters);
        $users = $query->get_results();

        echo '<div class="wrap">';
        echo '<div class="etracker-wrap">';
        echo '<h1>' . esc_html__('ETracker Access Management', 'etracker') . '</h1>';

        $this->render_notices();
        $this->render_search_form($filters);
        $this->render_users_table($users, $query, $filters);

        echo '</div></div>';
    }

    private function collect_filters(): array
    {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $access = isset($_GET['access']) ? sanitize_text_field(wp_unslash($_GET['access'])) : 'all';
        $role = isset($_GET['role']) ? sanitize_text_field(wp_unslash($_GET['role'])) : '';

        if (! in_array($access, ['all', 'granted', 'not_granted'], true)) {
            $access = 'all';
        }

        return [
            'search' => $search,
            'paged' => $paged,
            'access' => $access,
            'role' => $role,
        ];
    }

    private function maybe_handle_post(): void
    {
        if (! isset($_POST['etracker_access_action'])) {
            return;
        }

        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(wp_unslash($_POST['_wpnonce']), self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed.', 'etracker'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['etracker_access_action']));
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($userId <= 0 || $userId === get_current_user_id()) {
            return;
        }

        $capability = $this->capability_manager->get_capability();

        if ($action === 'revoke') {
            if (! $this->capability_manager->user_has_direct_cap($userId)) {
                $action = 'revoke_role';
            } else {
                $this->capability_manager->revoke($userId);
            }
        } elseif ($action === 'grant') {
            $this->capability_manager->grant($userId);
        }

        $redirect = add_query_arg(
            array_filter([
                'page' => self::PAGE_SLUG,
                's' => isset($_POST['search_term']) ? sanitize_text_field(wp_unslash($_POST['search_term'])) : '',
                'paged' => isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1,
                'access' => isset($_POST['access_filter']) ? sanitize_text_field(wp_unslash($_POST['access_filter'])) : 'all',
                'role' => isset($_POST['role_filter']) ? sanitize_text_field(wp_unslash($_POST['role_filter'])) : '',
                'updated' => 'true',
                'notice' => $action === 'revoke_role' ? 'error' : 'success',
                'updated_action' => $action,
            ]),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function query_users(array $filters): WP_User_Query
    {
        $args = [
            'number' => self::PER_PAGE,
            'paged' => $filters['paged'],
            'orderby' => 'display_name',
            'order' => 'ASC',
            'count_total' => true,
        ];

        if ($filters['search'] !== '') {
            $args['search'] = '*' . esc_attr($filters['search']) . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'display_name', 'user_email'];
        }

        if ($filters['role'] !== '') {
            $args['role'] = $filters['role'];
        }

        $capability = $this->capability_manager->get_capability();
        if ($filters['access'] === 'granted') {
            $args['capability'] = $capability;
        } elseif ($filters['access'] === 'not_granted') {
            $args['capability__not_in'] = [$capability];
        }

        return new WP_User_Query($args);
    }

    private function render_notices(): void
    {
        if (! isset($_GET['updated'])) {
            return;
        }

        $action = isset($_GET['updated_action']) ? sanitize_text_field(wp_unslash($_GET['updated_action'])) : '';
        $type = isset($_GET['notice']) && $_GET['notice'] === 'error' ? 'error' : 'success';

        if ($action === 'revoke_role') {
            $message = esc_html__('Cannot revoke access inherited from the user’s role. Remove the role capability to proceed.', 'etracker');
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        $message = $action === 'revoke'
            ? esc_html__('Access revoked successfully.', 'etracker')
            : esc_html__('Access granted successfully.', 'etracker');

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function render_search_form(array $filters): void
    {
        $roles = get_editable_roles();

        echo '<form method="get" class="etracker-search-form" style="margin-bottom:24px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';

        echo '<label>' . esc_html__('Search Users', 'etracker');
        echo '<input type="text" name="s" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr__('Name or email', 'etracker') . '" />';
        echo '</label>';

        echo '<label>' . esc_html__('Role', 'etracker');
        echo '<select name="role">';
        echo '<option value="">' . esc_html__('All roles', 'etracker') . '</option>';
        foreach ($roles as $roleKey => $roleData) {
            $label = translate_user_role($roleData['name']);
            echo '<option value="' . esc_attr($roleKey) . '"' . selected($filters['role'], $roleKey, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label>' . esc_html__('Access status', 'etracker');
        echo '<select name="access">';
        $accessOptions = [
            'all' => esc_html__('All users', 'etracker'),
            'granted' => esc_html__('With access', 'etracker'),
            'not_granted' => esc_html__('Without access', 'etracker'),
        ];
        foreach ($accessOptions as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($filters['access'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<div class="etracker-search-actions">';
        echo '<button type="submit" class="etracker-button">' . esc_html__('Filter', 'etracker') . '</button>';
        $resetUrl = add_query_arg('page', self::PAGE_SLUG, admin_url('admin.php'));
        echo '<a class="etracker-button secondary" href="' . esc_url($resetUrl) . '">' . esc_html__('Reset', 'etracker') . '</a>';
        echo '</div>';
        echo '</form>';
    }

    private function render_users_table(array $users, WP_User_Query $query, array $filters): void
    {
        echo '<div class="etracker-card">';
        echo '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Users', 'etracker') . '</h2></div>';
        echo '<div class="etracker-card__body">';

        if (empty($users)) {
            echo '<p class="etracker-empty-state">' . esc_html__('No users found for your filter set.', 'etracker') . '</p>';
            echo '</div></div>';
            return;
        }

        $roles = get_editable_roles();

        echo '<table class="etracker-enforcement-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Email', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Roles', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Access', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Actions', 'etracker') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($users as $user) {
            $hasAccess = $this->capability_manager->user_has_cap($user->ID);
            $hasDirect = $this->capability_manager->user_has_direct_cap($user->ID);
            $roleLabels = [];
            foreach ($user->roles as $slug) {
                $roleLabels[] = isset($roles[$slug]) ? translate_user_role($roles[$slug]['name']) : $slug;
            }
            $roleText = empty($roleLabels) ? '—' : implode(', ', $roleLabels);

            echo '<tr>';
            echo '<td>' . esc_html($user->display_name) . '<br /><a href="' . esc_url(get_edit_user_link($user->ID)) . '" target="_blank">' . esc_html__('Edit user', 'etracker') . '</a></td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html($roleText) . '</td>';
            if ($hasAccess && ! $hasDirect) {
                echo '<td><span class="etracker-pill active">' . esc_html__('Granted via role', 'etracker') . '</span></td>';
            } elseif ($hasAccess) {
                echo '<td><span class="etracker-pill active">' . esc_html__('Granted', 'etracker') . '</span></td>';
            } else {
                echo '<td><span class="etracker-pill">' . esc_html__('Not granted', 'etracker') . '</span></td>';
            }
            echo '<td>';
            if ($hasAccess && ! $hasDirect) {
                echo '<span>' . esc_html__('Managed via role assignment', 'etracker') . '</span>';
            } else {
                echo '<form method="post" class="etracker-action-form">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $user->ID) . '" />';
                echo '<input type="hidden" name="search_term" value="' . esc_attr($filters['search']) . '" />';
                echo '<input type="hidden" name="paged" value="' . esc_attr((string) $filters['paged']) . '" />';
                echo '<input type="hidden" name="access_filter" value="' . esc_attr($filters['access']) . '" />';
                echo '<input type="hidden" name="role_filter" value="' . esc_attr($filters['role']) . '" />';
                echo '<input type="hidden" name="etracker_access_action" value="' . ($hasAccess ? 'revoke' : 'grant') . '" />';
                echo '<button type="submit" class="etracker-button' . ($hasAccess ? ' secondary' : '') . '">' . esc_html($hasAccess ? __('Revoke', 'etracker') : __('Grant', 'etracker')) . '</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $this->render_pagination($query, $filters);

        echo '</div></div>';
    }

    private function render_pagination(WP_User_Query $query, array $filters): void
    {
        $totalUsers = (int) $query->get_total();
        $perPage = (int) $query->query_vars['number'];
        $totalPages = $perPage > 0 ? (int) ceil($totalUsers / $perPage) : 0;

        if ($totalPages <= 1) {
            return;
        }

        echo '<div class="tablenav"><div class="tablenav-pages">';

        $baseArgs = array_filter([
            'page' => self::PAGE_SLUG,
            's' => $filters['search'],
            'access' => $filters['access'],
            'role' => $filters['role'],
        ]);

        $baseUrl = add_query_arg($baseArgs, admin_url('admin.php'));

        for ($page = 1; $page <= $totalPages; $page++) {
            $class = $page === $filters['paged'] ? ' class="page-numbers current"' : ' class="page-numbers"';
            $url = esc_url(add_query_arg('paged', $page, $baseUrl));
            echo '<a' . $class . ' href="' . $url . '">' . esc_html((string) $page) . '</a> ';
        }

        echo '</div></div>';
    }
}


