<?php

class Gallery_dashboard {

    public $name = "Galeria";
    public $limit = 6;

    public function __construct() {
        $CI =& get_instance();
        $search = $this->form_search();
        $files = $search['files'];
        $total = $search['total'];
        if (check_method('upload')) {
            add_js(array(
                '/plugins/dropzone/js/dropzone.js'
            ));
            add_css(array(
                '/plugins/dropzone/css/dropzone.css',
            ));
        }
        
        add_js(array(
            '/plugins/fancybox/js/jquery.fancybox-buttons.js',
            '/plugins/fancybox/js/jquery.fancybox.pack.js',
            '/plugins/embeddedjs/ejs.js',
            'js/scripts_dashboard.js'
        ),'gallery');
        add_css(array(
            '/plugins/fancybox/css/jquery.fancybox.css',
            '/plugins/fancybox/css/jquery.fancybox-buttons.css',
            'css/style.css'
        ),'gallery');
        
        $vars = array(
            'title' => 'Galeria',
            'files' => $files
        );
        
        $CI->load->view_app('dashboard', 'gallery', $vars);
    }
    
    /*
     * Método para pesquisar arquivos
     */

    private function form_search() {
        $CI =& get_instance();
        $keyword = $CI->input->get('search');
        $per_page = (int) $CI->input->get('per_page') or 0;
        $CI->form_validation->set_rules('search', 'Pesquisa', 'trim|required');
        $CI->form_validation->run();

        $limit = $this->limit;
        $CI->load->model_app('files_model', 'gallery');
        $files = $CI->files_model->search($keyword, $limit, $per_page);
        $total = $CI->files_model->search_total_rows($keyword);
        return array(
            'files' => $files,
            'total' => $total
        );
    }

}