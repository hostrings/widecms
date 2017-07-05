<?php

defined('BASEPATH') OR exit('No direct script access allowed');
$path_modules = 'application/apps/projects/projects/';
$CI = &get_instance();
$CI->lang->load_app('projects_permissions', 'projects');
$CI->load->app('projects')->model('projects_model');
$CI->load->app('projects')->model('pages_model');
$CI->load->app('projects')->model('sections_model');

function check_list($fields)
{
    if ($fields) {
        foreach ($fields as $field) {
            if ($field['input']['list_registers'] == '1') {
                return true;
            }
        }
    }

    return false;
}
$projects = $CI->projects_model->list_projects_permissions();
if ($projects) {
    foreach ($projects as $project) {
        $name_project = $project['name'];
        $project_dir = $project['directory'];
        $pages = $CI->pages_model->list_pages_permissions($project_dir);
        if ($pages) {
            foreach ($pages as $page) {
                $name_page = $page['name'];
                $page_dir = $page['directory'];
                $sections = $CI->sections_model->list_sections_permissions($project_dir, $page_dir);
                if ($sections) {
                    foreach ($sections as $section) {
                        $name_section = $section['name'];
                        $section_dir = $section['directory'];
                        $fields = $section['fields'];
                        $module = $path_modules . $project_dir . '/' . $page_dir . '/' . $section_dir . '/controllers/' . ucfirst($section_dir) . '.php';
                        $method = $project_dir . '-' . $page_dir . '-' . $section_dir;
                        $page = 'project/' . $project_dir . '/' . $page_dir . '/' . $section_dir;
                        $list = check_list($fields);

                        if (!is_file($module)) {
                            if ($list) {
                                $permission[$page][$method] = '<strong>' . $name_project . ' - ' . $name_page . ' - ' . $name_section . '</strong>';
                                $permission[$page][$page . '/' . 'create'][$method . '-create'] = $CI->lang->line('projects_create');
                                $permission[$page][$page . '/' . 'edit/.*'][$method . '-edit'] = $CI->lang->line('projects_edit');
                                $permission[$page][$page . '/' . $section_dir . '/' . 'remove/.*'][$method . '-remove'] = $CI->lang->line('projects_remove');
                            } else {
                                $permission[$page][$method] = '<strong>' . $name_project . ' - ' . $name_page . ' - ' . $name_section . '</strong> - ' . $CI->lang->line('projects_edit');
                            }
                        } else {
                            $file_permissions = $path_modules . $project_dir . '/' . $page_dir . '/' . $section_dir . '/config/permissions.php';
                            if (is_file($file_permissions)) {
                                require($file_permissions);
                            }
                        }
                    }
                }
            }
        }
    }
}
