<?php

include_once INIT::$MODEL_ROOT . "/queries.php";
include_once INIT::$UTILS_ROOT . "/cat.class.php";

class checkFileConversionController extends ajaxcontroller {

      private $file_name;

      public function __construct() {
            parent::__construct();
            $this->file_name = $this->get_from_get_post('file_name');
      }

      public function doAction() {
            if (empty($this->file_name)) {
                  $this->result['errors'][] = array("code" => -1, "message" => "Missing file name.");
                  return false;
            }
            $intDir = $_SERVER['DOCUMENT_ROOT'] . '/storage/upload/' . $_COOKIE['upload_session'] . '_converted';

            $file_path = $intDir . '/' . $this->file_name . '.sdlxliff';
            
//    	log::doLog('FILEPATH: ' .$file_path);

            if (file_exists($file_path)) {
                  $this->result['converted'] = 1;
            } else {
                  $this->result['converted'] = 0;
			}
            $this->result['file_name'] = $this->file_name;
			
          
      }

}

?>