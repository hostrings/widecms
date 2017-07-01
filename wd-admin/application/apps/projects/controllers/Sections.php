<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Sections extends MY_Controller
{
    private $config_path = '';

    public function __construct()
    {
        parent::__construct();
        func_only_dev();
        $this->app_data = $this->apps->data_app();
        $this->load->app()->model('sections_model');
        $this->config_path = 'application/' . APP_PATH . 'projects/';
    }

    public function index()
    {
        $this->lang->load_app('sections/sections');
        $project = get_project();
        $page = get_page();
        $project_dir = $project['directory'];
        $page_dir = $page['directory'];
        $sections = $this->form_search($project_dir, $page_dir);
        $total = $this->sections_model->total_sections($project_dir, $page_dir);
        $this->data = array_merge($this->data, array(
            'title' => $page['name'],
            'name_app' => $this->app_data['name'],
            'sections' => $sections,
            'total' => $total,
            'project' => $project,
            'page' => $page,
            'search' => $this->input->get('search')
        ));

        echo $this->load->app()->render('dev-sections/index.twig', $this->data);
    }

    private function form_search($project_dir, $page_dir)
    {
        $this->load->library('form_validation');
        $this->form_validation->set_rules('search', $this->lang->line(APP . '_field_search'), 'trim|required');
        $this->form_validation->run();
        $keyword = $this->input->get('search');
        return $this->sections_model->search($project_dir, $page_dir, $keyword);
    }

    public function export($section_dir)
    {
        $this->lang->load_app('sections/sections');
        $project = get_project();
        $page = get_page();
        $project_dir = $project['directory'];
        $page_dir = $page['directory'];
        $section = $this->sections_model->get_section($project_dir, $page_dir, $section_dir);
        if (!$section) {
            redirect();
        }

        $path_section = $this->config_path . $project_dir . '/' . $page_dir . '/' . $section_dir . '/';
        $cache = APPPATH . 'cache/';
        if (!is_file($path_section . 'section.yml')) {
            exit();
        }

        if (!is_writable($cache)) {
            exit();
        }

        $name_zip = 'section-' . strtolower($section['name']) . '.zip';
        $path_zip = $cache . $name_zip;
        $source = str_replace('\\', '/', realpath($path_section)) . '/';
        $zip = new ZipArchive;
        $zip->open($path_zip, ZIPARCHIVE::CREATE);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            $file = str_replace('\\', '/', realpath($file));
            if (is_dir($file) === true) {
                $zip->addEmptyDir('section/' . str_replace($source, '', $file . '/'));
            } elseif (is_file($file) === true) {
                $zip->addFile($file, 'section/' . str_replace($source, '', $file));
            }
        }

        $zip->close();

        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path_zip)) . ' GMT');
        header('Content-Type: application/force-download');
        header('Content-Disposition: inline; filename="' . $name_zip . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($path_zip));
        header('Connection: close');
        readfile($path_zip);

        if (is_file($path_zip)) {
            unlink($path_zip);
        }

        exit();
    }

    public function import()
    {
        $this->lang->load_app('sections/import');
        $this->load->library('form_validation');
        $this->load->library('error_reporting');
        $project = get_project();
        $page = get_page();

        if (empty($_FILES['file']['name'])) {
            $this->form_validation->set_rules('file', $this->lang->line(APP . '_file_field'), 'required');
        } else {
            $this->form_validation->set_rules('file', $this->lang->line(APP . '_file_field'), 'trim');
        }

        $run = $this->form_validation->run();
        try {
            if ($run) {
                $upload_path = APPPATH . APP_PATH . 'tmp/';
                if (is_dir($upload_path)) {
                    forceRemoveDir($upload_path);
                }

                mkdir($upload_path, 0750);

                $config['upload_path'] = $upload_path;
                $config['allowed_types'] = 'zip';
                $this->load->library('upload', $config);
                $upload = $this->upload->do_upload('file');
                if (!$upload) {
                    throw new Exception($this->upload->display_errors('', ''));
                }

                $data = $this->upload->data();
                $file_zip = $data['file_name'];

                $zip = new ZipArchive;
                $zip->open($upload_path . $file_zip, ZIPARCHIVE::CREATE);
                $zip->extractTo($upload_path);
                $zip->close();
                unlink($upload_path . $file_zip);

                $section_path = $upload_path . 'section';
                if (!is_file($section_path . '/section.yml')) {
                    forceRemoveDir($upload_path);
                    throw new Exception($this->lang->line(APP . '_section_not_found'));
                }

                app_redirect('project/' . $project['directory'] . '/' . $page['directory'] . '/create?import=true');
            }

            $validation_errors = validation_errors();
            if (!empty($validation_errors)) {
                throw new Exception($validation_errors);
            }
        } catch (Exception $e) {
            $this->error_reporting->set_error($e->getMessage());
        }

        $this->data = array_merge($this->data, array(
            'title' => $this->lang->line(APP . '_import_title'),
            'project' => $project,
            'page' => $page,
            'name_app' => $this->app_data['name'],
            'errors' => $this->error_reporting->get_errors()
        ));

        echo $this->load->app()->render('dev-sections/import.twig', $this->data);
    }

    public function edit($section_dir)
    {
        $this->lang->load_app('sections/form');
        $project = get_project();
        $page = get_page();
        $fields = false;
        $project_dir = $project['directory'];
        $page_dir = $page['directory'];
        $section = $this->sections_model->get_section($project_dir, $page_dir, $section_dir);
        $this->load->app()->library('config_page');
        $this->load->app()->library('plugins_input');
        if ($section) {
            $fields = $this->treat_fields($section['fields']);
            $this->form_edit_section($project, $page, $section);
        } else {
            $this->error_reporting->set_error($this->lang->line(APP . '_open_config_fail'));
        }

        $this->include_components
                ->vendor_js('components/jqueryui/jquery-ui.min.js')
                ->vendor_css('components/jqueryui/themes/ui-lightness/jquery-ui.min.css')
                ->main_js('plugins/embeddedjs/ejs.js')
                ->app_js(array('js/masks/js/jquery.meio.js', 'project/js/form-section.js'))
                ->app_css('project/css/form-section.css');
        $this->data = array_merge($this->data, array(
            'fields' => $fields,
            'title' => sprintf($this->lang->line(APP . '_title_edit_section'), $section['name']),
            'name_app' => $this->app_data['name'],
            'name' => $section['name'],
            'directory' => $section['directory'],
            'table' => str_replace($project['preffix'], '', $section['table']),
            'status' => $section['status'],
            'preffix' => $project['preffix'],
            'project' => $project,
            'page' => $page,
            'section' => $section,
            'sections' => $this->list_options($project_dir, $page_dir, $section_dir),
            'inputs' => $this->config_page->inputs(),
            'types' => $this->config_page->types(),
            'plugins_input' => $this->plugins_input->list_plugins(),
            'errors' => $this->error_reporting->get_errors(),
            'query_string' => $this->input->server('QUERY_STRING'),
            'total' => count($this->input->post('name_field'))
        ));

        echo $this->load->app()->render('dev-sections/form.twig', $this->data);
    }

    private function list_options($project_dir, $page_dir, $section_dir = null)
    {
        $options = $this->sections_model->list_sections_select($project_dir, $page_dir, $section_dir);
        $options[] = array('table' => 'wd_users', 'name' => $this->lang->line(APP . '_option_users'));

        return $options;
    }

    private function form_edit_section($project, $page, $section)
    {
        $this->load->library('form_validation');
        $this->load->library('error_reporting');
        $this->form_validation->set_rules('name', $this->lang->line(APP . '_label_name'), 'trim|required');
        $this->form_validation->set_rules('directory', $this->lang->line(APP . '_label_directory'), 'trim|required|callback_verify_dir_edit');
        $this->form_validation->set_rules('table', $this->lang->line(APP . '_label_table'), 'trim|required|callback_verify_table_edit');
        if ($this->form_validation->run()) {
            $project_dir = $project['directory'];
            $page_dir = $page['directory'];
            $section_dir = $section['directory'];
            $data = $this->get_post_data($project, $page, $section);
            $data['old_config'] = $section;
            $directory = $data['directory'];

            $dir = $this->config_path . $project_dir . '/' . $page_dir . '/';
            rename($dir . $section_dir, $dir . $directory);
            $edit = $this->edit_config($data, $project_dir, $page_dir, $section_dir);
            if ($edit) {
                app_redirect('project/' . $project_dir . '/' . $page_dir);
            }
        } else {
            $this->error_reporting->set_error(validation_errors());
        }
    }

    public function verify_dir_edit($directory)
    {
        $project = get_project();
        $page = get_page();
        $section = get_section($this->uri->segment(7));
        $dir_project = $project['directory'];
        $dir_page = $page['directory'];
        $dir_section = $section['directory'];
        $dir = $this->config_path . $dir_project . '/' . $dir_page . '/';
        if ($section['directory'] != $directory) {
            if (!is_writable($dir . $dir_section)) {
                $this->form_validation->set_message('verify_dir_edit', sprintf($this->lang->line(APP . '_not_allowed_folder_rename'), $directory));

                return false;
            }
        }

        return true;
    }

    public function verify_table_edit($table)
    {
        $project = get_project();
        $preffix = $project['preffix'];
        $table = $preffix . $table;

        $section = get_section($this->uri->segment(7));

        if ($table != $section['table']) {

            $check_table = $this->sections_model->check_table_exists($table);
            if ($check_table) {
                $this->form_validation->set_message('verify_table_edit', sprintf($this->lang->line(APP . '_table_exists'), $table));

                return false;
            }

            $preffix_wd = substr($table, 0, 3);
            if ($preffix_wd == 'wd_') {
                $this->form_validation->set_message('verify_table_edit', $this->lang->line(APP . '_not_allowed_preffix_wd'));

                return false;
            }
        }

        return true;
    }

    private function get_post_data($project, $page, $section = null)
    {
        $name = $this->input->post('name');
        $directory = slug($this->input->post('directory'));
        $table = $this->input->post('table');
        $status = $this->input->post('status');
        $name_field = $this->input->post('name_field');
        $input_field = $this->input->post('input_field');
        $list_reg_field = $this->input->post('list_registers_field');
        $column_field = $this->input->post('column_field');
        $type_field = $this->input->post('type_field');
        $limit_field = $this->input->post('limit_field');
        $plugins_field = $this->input->post('plugins_field');
        $observation_field = $this->input->post('observation_field');
        $attributes_field = $this->input->post('attributes_field');
        $required_field = $this->input->post('required_field');
        $unique_field = $this->input->post('unique_field');
        $default_field = $this->input->post('default_field');
        $comment_field = $this->input->post('comment_field');
        $remove_field = $this->input->post('remove_field');
        $options_field = $this->input->post('options_field');
        $label_options_field = $this->input->post('label_options_field');
        $trigger_select_field = $this->input->post('trigger_select_field');
        $position = $this->input->post('position');
        // upload
        $extensions_allowed = $this->input->post('extensions_allowed');
        $image_resize = $this->input->post('image_resize');
        $image_x = $this->input->post('image_x');
        $image_y = $this->input->post('image_y');
        $image_ratio = $this->input->post('image_ratio');
        $image_ratio_x = $this->input->post('image_ratio_x');
        $image_ratio_y = $this->input->post('image_ratio_y');
        $image_ratio_crop = $this->input->post('image_ratio_crop');
        $image_ratio_fill = $this->input->post('image_ratio_fill');
        $image_background_color = $this->input->post('image_background_color');
        $image_convert = $this->input->post('image_convert');
        $image_text = $this->input->post('image_text');
        $image_text_color = $this->input->post('image_text_color');
        $image_text_background = $this->input->post('image_text_background');
        $image_text_opacity = $this->input->post('image_text_opacity');
        $image_text_background_opacity = $this->input->post('image_text_background_opacity');
        $image_text_padding = $this->input->post('image_text_padding');
        $image_text_position = $this->input->post('image_text_position');
        $image_text_direction = $this->input->post('image_text_direction');
        $image_text_x = $this->input->post('image_text_x');
        $image_text_y = $this->input->post('image_text_y');
        $image_thumbnails = $this->input->post('image_thumbnails');

        $table = $project['preffix'] . $table;

        return array(
            'old_section' => $section,
            'project_directory' => $project['directory'],
            'page_directory' => $page['directory'],
            'name' => $name,
            'slug' => slug($directory),
            'directory' => $directory,
            'table' => $table,
            'status' => $status,
            'name_field' => $name_field,
            'input_field' => $input_field,
            'list_reg_field' => $list_reg_field,
            'column_field' => $column_field,
            'type_field' => $type_field,
            'limit_field' => $limit_field,
            'plugins_field' => $plugins_field,
            'required_field' => $required_field,
            'unique_field' => $unique_field,
            'default_field' => $default_field,
            'comment_field' => $comment_field,
            'observation_field' => $observation_field,
            'attributes_field' => $attributes_field,
            'remove_field' => $remove_field,
            'position' => $position,
            // Campos para configurar select
            'options_table' => $options_field,
            'options_label' => $label_options_field,
            'options_trigger_select' => $trigger_select_field,
            // Campos de upload
            'extensions_allowed' => preg_replace("/[^a-z0-9,]/", '', str_replace(array('|'), array(','), $extensions_allowed)),
            'image_resize' => $image_resize,
            'image_x' => $image_x,
            'image_y' => $image_y,
            'image_ratio' => $image_ratio,
            'image_ratio_x' => $image_ratio_x,
            'image_ratio_y' => $image_ratio_y,
            'image_ratio_crop' => $image_ratio_crop,
            'image_ratio_fill' => $image_ratio_fill,
            'image_background_color' => $image_background_color,
            'image_convert' => $image_convert,
            'image_text' => $image_text,
            'image_text_color' => $image_text_color,
            'image_text_background' => $image_text_background,
            'image_text_opacity' => $image_text_opacity,
            'image_text_background_opacity' => $image_text_background_opacity,
            'image_text_padding' => $image_text_padding,
            'image_text_position' => $image_text_position,
            'image_text_direction' => $image_text_direction,
            'image_text_x' => $image_text_x,
            'image_text_y' => $image_text_y,
            'image_thumbnails' => $image_thumbnails,
        );
    }

    private function edit_config($data, $project_dir, $page_dir, $section_dir)
    {
        $config = $this->treat_config($data);
        $fields = $config['fields'];
        $table = $data['table'];
        $old_section = $data['old_section'];
        $current_table = $old_section['table'];
        $old_fields = $data['old_config']['fields'];
        if (!$fields) {
            return false;
        }

        if ($table != $current_table) {
            $this->sections_model->rename_table($current_table, $table);
        }

        $x = 0;
        $new_config = array(
            'name' => $data['name'],
            'directory' => $data['directory'],
            'table' => $data['table'],
            'status' => $data['status'],
            'fields' => array()
        );

        foreach ($fields as $field) {
            $position = $field['position'];
            $column = $field['column'];
            $type = $field['type'];
            $limit = $field['limit'];
            $default = $field['default'];
            $comment = $field['comment'];
            $remove = (boolean) $field['remove'];

            if (isset($old_fields[$x]) && $remove) {
                $this->remove_field($field, $table);
            } elseif (isset($old_fields[$x])) {
                $old = $old_fields[$x]['database'];
                $old_column = $old['column'];
                $old_type = strtolower($old['type_column']);
                $old_limit = (isset($old['limit'])) ? $old['limit'] : '';
                $old_default = (isset($old['default'])) ? $old['default'] : '';
                $old_comment = (isset($old['comment'])) ? $old['comment'] : '';
                if ($old_column != $column || $old_type != $type || $old_limit != $limit || $old_default != $default || $old_comment != $comment) {
                    $field['old_column'] = $old_column;
                    $modify = $this->sections_model->modify_column($field, $table);
                    if (!$modify) {
                        $field['column'] = $old_column;
                        $field['type'] = $old_type;
                        $field['limit'] = $old_limit;
                        $field['default'] = $old_default;
                        $field['comment'] = $old_comment;
                        $this->error_reporting->set_error(sprintf($this->lang->line(APP . '_not_allowed_column_modify'), $old_column));
                    }
                }
            } else {
                $this->insert_field($table, $field);
            }

            if (!$remove) {
                $new_config['fields'][$position] = $field;
            }

            $x++;
        }

        ksort($new_config['fields']);
        return $this->sections_model->create($new_config, $project_dir, $page_dir, $data['directory']);
    }

    private function remove_field($data, $table)
    {
        $remove = $this->sections_model->remove_column($data, $table);
        if (!$remove) {
            $this->error_reporting->set_error(sprintf($this->lang->line(APP . '_not_allowed_column_remove'), $data['old_column']));
        }
    }

    private function insert_field($table, $data)
    {
        $field_insert = array();
        $field_insert[] = array(
            'column' => $data['column'],
            'type' => $data['type'],
            'limit' => $data['limit'],
            'default' => $data['default'],
            'comment' => $data['comment']
        );
        $this->sections_model->create_columns($table, $field_insert);
    }

    public function remove($section_dir)
    {
        $this->lang->load_app('sections/remove');
        $project = get_project();
        $page = get_page();
        $section = $this->sections_model->get_section($project['directory'], $page['directory'], $section_dir);
        if (!$section or ! $project or ! $page) {
            app_redirect();
        }

        $this->form_remove($section, $project, $page);
        $this->data = array_merge($this->data, array(
            'title' => sprintf($this->lang->line(APP . '_title_remove_section'), $section['name']),
            'name_app' => $this->app_data['name'],
            'project' => $project,
            'section' => $section,
            'page' => $page
        ));

        echo $this->load->app()->render('dev-sections/remove.twig', $this->data);
    }

    private function form_remove($section, $project, $page)
    {
        $this->load->library('form_validation');
        $this->form_validation->set_rules('password', $this->lang->line(APP . '_label_password'), 'required|callback_verify_password');
        $this->form_validation->set_rules('section', $this->lang->line(APP . '_label_section'), 'trim|required');
        if ($this->form_validation->run()) {
            $project_dir = $project['directory'];
            $page_dir = $page['directory'];
            if ($section['directory'] != $this->input->post('section')) {
                app_redirect('project/' . $project_dir . '/' . $page_dir);
            }

            $section_dir = $section['directory'];
            $table = $section['table'];
            $check = $this->sections_model->check_table_exists($table);
            if ($check) {
                $this->sections_model->remove_table($table);
            }

            forceRemoveDir($this->config_path . $project_dir . '/' . $page_dir . '/' . $section_dir);

            app_redirect('project/' . $project_dir . '/' . $page_dir);
        }
    }

    public function verify_password($v_pass)
    {
        $pass_user = $this->user_data['password'];
        $this->load->helper('passwordhash');
        $PasswordHash = new PasswordHash(PHPASS_HASH_STRENGTH, PHPASS_HASH_PORTABLE);
        if (!$PasswordHash->CheckPassword($v_pass, $pass_user)) {
            $this->form_validation->set_message('verify_password', $this->lang->line(APP . '_incorrect_password'));

            return false;
        }

        return true;
    }

    public function create()
    {
        $this->lang->load_app('sections/form');
        $this->load->app()->library('config_page');
        $this->load->app()->library('plugins_input');
        $project = get_project();
        $page = get_page();
        $this->form_create_section($project, $page);
        $this->include_components->main_js('plugins/embeddedjs/ejs.js')
                ->vendor_js('components/jqueryui/jquery-ui.min.js')
                ->vendor_css('components/jqueryui/themes/base/jquery-ui.min.css')
                ->app_js(array(
                    'js/masks/js/jquery.meio.js',
                    'project/js/form-section.js'
                ))
                ->app_css('project/css/form-section.css');

        $this->data = array_merge($this->data, array(
            'title' => sprintf($this->lang->line(APP . '_title_add_section'), $page['name']),
            'name_app' => $this->app_data['name'],
            'preffix' => $project['preffix'],
            'project' => $project,
            'page' => $page,
            'sections' => $this->list_options($project['directory'], $page['directory']),
            'inputs' => $this->config_page->inputs(),
            'types' => $this->config_page->types(),
            'plugins_input' => $this->plugins_input->list_plugins(),
            'errors' => $this->error_reporting->get_errors(),
            'query_string' => $this->input->server('QUERY_STRING'),
            'total' => count($this->input->post('name_field'))
        ));

        $import = (bool) $this->input->get('import');
        if ($import) {
            $section_path = APPPATH . APP_PATH . 'tmp/section/';
            $config = $this->sections_model->get_tmp_config($section_path . 'section.yml');
            $this->data['name'] = $config['name'];
            $this->data['directory'] = $config['directory'];
            $this->data['table'] = $config['table'];
            $this->data['status'] = $config['status'];
            $this->data['fields'] = $config['fields'];
        }

        echo $this->load->app()->render('dev-sections/form.twig', $this->data);
    }

    private function form_create_section($project, $page)
    {
        $this->load->library('form_validation');
        $this->load->library('error_reporting');
        $this->form_validation->set_rules('name', $this->lang->line(APP . '_label_name'), 'trim|required');
        $this->form_validation->set_rules('directory', $this->lang->line(APP . '_label_directory'), 'trim|required|callback_verify_dir_create');
        $this->form_validation->set_rules('table', $this->lang->line(APP . '_label_table'), 'trim|required|callback_verify_table_create');
        if ($this->form_validation->run()) {
            $import = (bool) $this->input->get('import');
            $project_dir = $project['directory'];
            $page_dir = $page['directory'];
            $data = $this->get_post_data($project, $page);
            $directory = $data['directory'];
            $table = $data['table'];

            $config = $this->treat_config($data);
            if (!$config) {
                return false;
            }

            $dir_section = $this->config_path . $project_dir . '/' . $page_dir . '/' . $directory;
            mkdir($dir_section, 0755);

            $this->sections_model->create($config, $data['project_directory'], $data['page_directory'], $data['directory']);
            $this->sections_model->create_table($table);
            $this->sections_model->create_columns($table, $config['fields']);


            if ($import) {
                $upload_path = APPPATH . APP_PATH . 'tmp/';
                $section_path = $upload_path . 'section/';
                if (is_dir($section_path)) {
                    $opendir = opendir($section_path);
                    while (false !== ($file = readdir($opendir))) {
                        if ($file != 'section.yml') {
                            rename($section_path . $file, $dir_section . '/' . $file);
                        }
                    }
                    forceRemoveDir($upload_path);
                }
            }

            app_redirect('project/' . $project_dir . '/' . $page_dir);
        } else {
            $this->error_reporting->set_error(validation_errors());
        }
    }

    public function verify_dir_create($directory)
    {
        $project = get_project();
        $page = get_page();
        $is_dir_section = is_dir($this->config_path . $project['directory'] . '/' . $page['directory'] . '/' . $directory);
        if ($is_dir_section) {
            $this->form_validation->set_message('verify_dir_create', sprintf($this->lang->line(APP . '_folder_exists'), $directory));

            return false;
        }

        $is_writable_section = is_writable($this->config_path . $project['directory'] . '/' . $page['directory']);
        if (!$is_writable_section) {
            $this->form_validation->set_message('verify_dir_create', $this->lang->line(APP . '_not_allowed_folder_create'));

            return false;
        }

        return true;
    }

    public function verify_table_create($table)
    {
        $project = get_project();
        $preffix = $project['preffix'];
        $table = $preffix . $table;


        $import = $this->input->post('import');
        $check_table = $this->sections_model->check_table_exists($table);
        if ($check_table && !$import) {
            $this->form_validation->set_message('verify_table_create', sprintf($this->lang->line(APP . '_table_exists'), $table));

            return false;
        }

        $preffix_wd = substr($table, 0, 3);
        if ($preffix_wd == 'wd_' && !$import) {
            $this->form_validation->set_message('verify_table_create', $this->lang->line(APP . '_not_allowed_preffix_wd'));

            return false;
        }

        return true;
    }

    protected function treat_config($data)
    {
        $total = count($data['name_field']);
        if (!$total) {
            $this->error_reporting->set_error($this->lang->line(APP . '_required_field'));
            return false;
        }

        $this->load->app()->library('config_page');
        $config = array(
            'name' => $data['name'],
            'directory' => $data['directory'],
            'table' => $data['table'],
            'status' => $data['status']
        );

        $remove_field = (isset($data['remove_field'])) ? $data['remove_field'] : array();
        for ($i = 0; $i < $total; $i++) {
            $name_field = (isset($data['name_field'][$i])) ? $data['name_field'][$i] : '';
            $input_field = (isset($data['input_field'][$i])) ? $data['input_field'][$i] : '';
            $list_reg_field = (isset($data['list_reg_field'][$i])) ? $data['list_reg_field'][$i] : '';
            $column_field = (isset($data['column_field'][$i])) ? $data['column_field'][$i] : '';
            $type_field = (isset($data['type_field'][$i])) ? $data['type_field'][$i] : '';
            $limit_field = (isset($data['limit_field'][$i])) ? $data['limit_field'][$i] : '';
            $required_field = (isset($data['required_field'][$i])) ? $data['required_field'][$i] : '';
            $unique_field = (isset($data['unique_field'][$i])) ? $data['unique_field'][$i] : '';
            $default_field = (isset($data['default_field'][$i])) ? $data['default_field'][$i] : '';
            $comment_field = (isset($data['comment_field'][$i])) ? $data['comment_field'][$i] : '';
            $plugins_field = (isset($data['plugins_field'][$i])) ? $data['plugins_field'][$i] : '';
            $observation_field = (isset($data['observation_field'][$i])) ? $data['observation_field'][$i] : '';
            $attributes_field = (isset($data['attributes_field'][$i])) ? $data['attributes_field'][$i] : '';
            $position = (isset($data['position'][$i])) ? $data['position'][$i] : '';
            $remove = (is_array($remove_field) && array_key_exists($i, $remove_field));
            // Select
            $options_field = (isset($data['options_table'][$i])) ? $data['options_table'][$i] : '';
            $label_options_field = (isset($data['options_label'][$i])) ? $data['options_label'][$i] : '';
            $trigger_select_field = (isset($data['options_trigger_select'][$i])) ? $data['options_trigger_select'][$i] : '';
            // Upload
            $extensions_allowed = (isset($data['extensions_allowed'][$i])) ? $data['extensions_allowed'][$i] : '';
            $image_resize = (isset($data['image_resize'][$i])) ? $data['image_resize'][$i] : '';
            $image_x = (isset($data['image_x'][$i])) ? $data['image_x'][$i] : '';
            $image_y = (isset($data['image_y'][$i])) ? $data['image_y'][$i] : '';
            $image_ratio = (isset($data['image_ratio'][$i])) ? $data['image_ratio'][$i] : '';
            $image_ratio_x = (isset($data['image_ratio_x'][$i])) ? $data['image_ratio_x'][$i] : '';
            $image_ratio_y = (isset($data['image_ratio_y'][$i])) ? $data['image_ratio_y'][$i] : '';
            $image_ratio_crop = (isset($data['image_ratio_crop'][$i])) ? $data['image_ratio_crop'][$i] : '';
            $image_ratio_fill = (isset($data['image_ratio_fill'][$i])) ? $data['image_ratio_fill'][$i] : '';
            $image_background_color = (isset($data['image_background_color'][$i])) ? $data['image_background_color'][$i] : '';
            $image_convert = (isset($data['image_convert'][$i])) ? $data['image_convert'][$i] : '';
            $image_text = (isset($data['image_text'][$i])) ? $data['image_text'][$i] : '';
            $image_text_color = (isset($data['image_text_color'][$i])) ? $data['image_text_color'][$i] : '';
            $image_text_background = (isset($data['image_text_background'][$i])) ? $data['image_text_background'][$i] : '';
            $image_text_opacity = (isset($data['image_text_opacity'][$i])) ? $data['image_text_opacity'][$i] : '';
            $image_text_background_opacity = (isset($data['image_text_background_opacity'][$i])) ? $data['image_text_background_opacity'][$i] : '';
            $image_text_padding = (isset($data['image_text_padding'][$i])) ? $data['image_text_padding'][$i] : '';
            $image_text_position = (isset($data['image_text_position'][$i])) ? $data['image_text_position'][$i] : '';
            $image_text_direction = (isset($data['image_text_direction'][$i])) ? $data['image_text_direction'][$i] : '';
            $image_text_x = (isset($data['image_text_x'][$i])) ? $data['image_text_x'][$i] : '';
            $image_text_y = (isset($data['image_text_y'][$i])) ? $data['image_text_y'][$i] : '';
            $image_thumbnails = (isset($data['image_thumbnails'][$i])) ? $data['image_thumbnails'][$i] : '';

            $verify = $this->verify_fields([
                'table' => $data['table'],
                'column_field' => $column_field,
                'name_field' => $name_field,
                'type_field' => $type_field,
                'options_field' => $options_field,
                'label_options_field' => $label_options_field,
                'input_field' => $input_field,
                'fields' => $config,
            ]);
            if (!$verify) {
                return false;
            }

            if (!empty($name_field) && !empty($input_field) && !empty($column_field) && !empty($type_field)) {
                if (empty($limit_field)) {
                    $search = search($this->config_page->types(), 'type', $type_field);
                    if (!empty($search[0]['constraint'])) {
                        $limit_field = $search[0]['constraint'];
                    }
                }

                $config['fields'][] = array(
                    'name' => $name_field,
                    'input' => $input_field,
                    'list_reg' => $list_reg_field,
                    'column' => $column_field,
                    'type' => $type_field,
                    'limit' => $limit_field,
                    'plugins' => $plugins_field,
                    'observation' => $observation_field,
                    'attributes' => $attributes_field,
                    'required' => $required_field,
                    'unique' => $unique_field,
                    'default' => $default_field,
                    'comment' => $comment_field,
                    'remove' => $remove,
                    'position' => $position,
                    // Select
                    'options_table' => $options_field,
                    'options_label' => $label_options_field,
                    'options_trigger_select' => $trigger_select_field,
                    // Upload
                    'extensions_allowed' => $extensions_allowed,
                    'image_resize' => $image_resize,
                    'image_x' => $image_x,
                    'image_y' => $image_y,
                    'image_ratio' => $image_ratio,
                    'image_ratio_x' => $image_ratio_x,
                    'image_ratio_y' => $image_ratio_y,
                    'image_ratio_crop' => $image_ratio_crop,
                    'image_ratio_fill' => $image_ratio_fill,
                    'image_background_color' => $image_background_color,
                    'image_convert' => $image_convert,
                    'image_text' => $image_text,
                    'image_text_color' => $image_text_color,
                    'image_text_background' => $image_text_background,
                    'image_text_opacity' => $image_text_opacity,
                    'image_text_background_opacity' => $image_text_background_opacity,
                    'image_text_padding' => $image_text_padding,
                    'image_text_position' => $image_text_position,
                    'image_text_direction' => $image_text_direction,
                    'image_text_x' => $image_text_x,
                    'image_text_y' => $image_text_y,
                    'image_thumbnails' => $image_thumbnails,
                );
            }
        }

        return $config;
    }

    protected function verify_fields($data)
    {
        $table = $data['table'];
        $column_field = $data['column_field'];
        $name_field = $data['name_field'];
        $type_field = $data['type_field'];
        $input_field = $data['input_field'];
        $fields = $data['fields'];
        $options_field = $data['options_field'];
        $label_options_field = $data['label_options_field'];
        if ($column_field == 'id') {
            $this->error_reporting->set_error($this->lang->line(APP . '_create_column_id_not_allowed'));

            return false;
        } elseif ($column_field == $table) {
            $this->error_reporting->set_error($this->lang->line(APP . '_column_equals_table'));

            return false;
        } elseif (count(search($fields, 'column', $column_field)) > 0) {
            $this->error_reporting->set_error(sprintf($this->lang->line(APP . '_duplicate_field_not_allowed'), $column_field));

            return false;
        } elseif (!empty($name_field) && (empty($input_field) or empty($column_field) or empty($type_field))) {
            $this->error_reporting->set_error($this->lang->line(APP . '_fields_required'));

            return false;
        } elseif (!empty($options_field) && empty($label_options_field)) {
            $this->error_reporting->set_error(sprintf($this->lang->line(APP . '_options_select_required'), $name_field));
        } else {
            return true;
        }
    }

    private function get_page()
    {
        $page = $this->uri->segment(3);
        if (empty($this->page)) {
            $this->load->app()->model('pages_model');
            $this->page = $this->pages_model->get_section($page);
        }

        return $this->page;
    }

    public function list_columns_json()
    {
        $table = $this->input->post('table');
        $cols = array();
        if (!empty($table)) {
            $verify_table = $this->sections_model->check_table_exists($table);
            if ($verify_table) {
                // Lista todas as colunas do banco de dados de uma determinada tabela
                $cols = $this->sections_model->list_columns($table);
            }
        }

        echo json_encode($cols);
    }

    private function list_columns($table)
    {
        $cols = array();
        if (!empty($table)) {
            $verify_table = $this->sections_model->check_table_exists($table);
            if ($verify_table) {
                $cols = $this->sections_model->list_columns($table);
            }
        }

        return $cols;
    }

    private function treat_fields($fields)
    {
        $new_fields = array();
        foreach ($fields as $field) {
            if (isset($field['input']['options']['table']) && !empty($field['input']['options']['table'])) {
                $table = $field['input']['options']['table'];
                $field['options_selected'] = json_encode($this->list_columns($table));
            }

            $new_fields[] = $field;
        }

        return $new_fields;
    }

    public function list_columns_import()
    {
        func_only_dev();
        $this->form_validation->set_rules('table', 'Tabela', 'required');
        try {
            if ($this->form_validation->run()) {
                $table = $this->input->post('table');
                $columns = $this->treat_columns($this->sections_model->list_columns_import($table));
                $col_primary = search($columns, 'Key', 'PRI');
                if (!$col_primary) {
                    throw new Exception($this->lang->line(APP . '_pk_required'));
                }

                $col_primary = $col_primary[0];
                if ($col_primary['Field'] != 'id') {
                    throw new Exception(sprintf($this->lang->line(APP . '_pk_id_name_required'), $col_primary['Field']));
                }

                echo json_encode(array('error' => false, 'columns' => $columns));
            } else {
                $validation_errors = validation_errors();
                throw new Exception($validation_errors);
            }
        } catch (Exception $e) {
            echo json_encode(array('error' => true, 'message' => $e->getMessage()));
        }
    }

    public function image_example()
    {
        $image_y = $this->input->get("image_y");
        $image_x = $this->input->get("image_x");
        $image_resize = boolval($this->input->get("image_resize"));
        $image_ratio = boolval($this->input->get("image_ratio"));
        $image_ratio_x = $this->input->get("image_ratio_x");
        $image_ratio_y = $this->input->get("image_ratio_y");
        $image_ratio_crop = boolval($this->input->get("image_ratio_crop"));
        $image_ratio_fill = boolval($this->input->get("image_ratio_fill"));
        $image_background_color = $this->input->get("image_background_color");
        $image_convert = boolval($this->input->get("image_convert"));
        $image_text = $this->input->get("image_text");
        $image_text_color = $this->input->get("image_text_color");
        $image_text_background = $this->input->get("image_text_background");
        $image_text_opacity = $this->input->get("image_text_opacity");
        $image_text_background_opacity = $this->input->get("image_text_background_opacity");
        $image_text_padding = $this->input->get("image_text_padding");
        $image_text_position = $this->input->get("image_text_position");
        $image_text_direction = $this->input->get("image_text_direction");
        $image_text_x = $this->input->get("image_text_x");
        $image_text_y = $this->input->get("image_text_y");
        $tmp = new upload(APP_ASSETS . 'images/test.png');
        if (!empty($image_resize)) {
            $tmp->image_resize = $image_resize;
        }

        if (!empty($image_y)) {
            $tmp->image_y = $image_y;
        }

        if (!empty($image_x)) {
            $tmp->image_x = $image_x;
        }

        if (!empty($image_ratio)) {
            $tmp->image_ratio = $image_ratio;
        }

        if (!empty($image_ratio_x)) {
            $tmp->image_ratio_x = $image_ratio_x;
        }

        if (!empty($image_ratio_y)) {
            $tmp->image_ratio_y = $image_ratio_y;
        }

        if (!empty($image_ratio_crop)) {
            $tmp->image_ratio_crop = $image_ratio_crop;
        }

        if (!empty($image_ratio_fill)) {
            $tmp->image_ratio_fill = $image_ratio_fill;
        }

        if (!empty($image_background_color)) {
            $tmp->image_background_color = $image_background_color;
        }

        if (!empty($image_convert)) {
            $tmp->image_convert = $image_convert;
        }

        if (!empty($image_text)) {
            $tmp->image_text = $image_text;
        }

        if (!empty($image_text_color)) {
            $tmp->image_text_color = $image_text_color;
        }

        if (!empty($image_text_background)) {
            $tmp->image_text_background = $image_text_background;
        }

        if (!empty($image_text_opacity)) {
            $tmp->image_text_opacity = $image_text_opacity;
        }

        if (!empty($image_text_background_opacity)) {
            $tmp->image_text_background_opacity = $image_text_background_opacity;
        }

        if (!empty($image_text_padding)) {
            $tmp->image_text_padding = $image_text_padding;
        }

        if (!empty($image_text_position)) {
            $tmp->image_text_position = $image_text_position;
        }

        if (!empty($image_text_direction)) {
            $tmp->image_text_direction = $image_text_direction;
        }

        if (!empty($image_text_x)) {
            $tmp->image_text_x = $image_text_x;
        }

        if (!empty($image_text_y)) {
            $tmp->image_text_y = $image_text_y;
        }

        header('Content-type: ' . $tmp->file_src_mime);

        echo $tmp->Process();
    }

    private function treat_columns($columns)
    {
        if ($columns) {
            $this->load->app()->library('config_page');
            $types = $this->config_page->types();
            $cols = array();
            foreach ($columns as $col) {
                $type = $col['Type'];
                $type_current = $this->treat_type(preg_replace('/\(.+?\)/', '', $type));
                $limit_current = preg_replace('/.+?\((.+?)\)/', '$1', $type);
                $verify_type = search($types, 'type', $type_current);
                if (!$verify_type) {
                    throw new Exception(sprintf($this->lang->line(APP . '_type_column_not_exists'), $col['Field'], $type_current));
                }

                $col['Type'] = $type_current;
                $col['Limit'] = $limit_current;
                $cols[] = $col;
            }

            $columns = $cols;
        }

        return $columns;
    }

    private function treat_type($type)
    {
        if ($type == 'int') {
            $type = 'integer';
        }

        return $type;
    }
}