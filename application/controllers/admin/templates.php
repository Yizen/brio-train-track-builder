<?php
class Templates extends CI_Controller {
	function __construct() {
        parent::__construct();
        
        // Load the URL helper so redirects work.
        $this->load->helper('url');
        $this->load->library('messages');

        // this is used for the CRUD
		$this->load->model('template_model');
    }
    
    private function parse_illustrator($raw) {
		$this->load->helper('JTokenizer');
		
		$geo = array();
		
		//Get data from the canvas
		if ( preg_match("/width=\"([0-9]*)\"/", $raw, $match)) {
			$width_value = $match[1];
			$width_value = intval($width_value);
				
			$geo["regX"] = floor($width_value  / 2);
		} 
		
		if ( preg_match("/height=\"(.*)\"/", $raw, $match)) {
			$height_value = $match[1];
			$height_value = intval($height_value);
		
			$geo["regY"] = floor($height_value / 2);
		}	
		
		$geo["influence"] = max ($width_value, $height_value);
	
		
		//Isolate the script to parse the geometry using the Javascript tokenizer
		if ( preg_match('/(<script.*>)(.*)(<\/script>)/imxsU',$raw, $script)) {
			$raw = $script[2];
		}
		
		$tokens = j_token_get_all( $raw );

		$state = "START";
		$expected = "";
		
		$graphics = array();
		$connectors = array();
		$segments = array();
		
		$nextGraphics = array();
		$nextConnector = array();
		$nextSegment = array();

		
		foreach($tokens as $token) {
		
			if ($token[0] == J_COMMENT) {
				//Which context ?
				if( preg_match("/graphics/",$token[1])) {
					$state = "GRAPHICS";
					//Flush previous graphics
					if (sizeof($nextGraphics)>0)
						$graphics["graphics"][] = $nextGraphics;
						
					$nextGraphics = array();

				}
				
				if( preg_match("/connectors\/(.*)\/(.*)/",$token[1], $results)) {
					$state = "CONNECTORS";
					
					//Flush previous connector
					if (sizeof($nextConnector)>0)
						$connectors["connectors"][] = $nextConnector;
						
					$nextConnector = array();

					$nextConnector["name"] = $results[1];
					$nextConnector["type"] = strtoupper($results[2]);
					$nextConnector["isAxisForFlip"] = "true";

				}
				
				if( preg_match("/segments\/(.*) (.*) (.*)/",$token[1], $results)) {
					$state = "SEGMENTS";
					
					//Flush previous segment
					if (sizeof($nextSegment)>0)
						$segments["segments"][] = $nextSegment;
						
					$nextSegment = array();
					$nextSegment["type"] = strtoupper( $results[1] );
					$nextSegment["connectorA"] = $results[2];
					$nextSegment["connectorB"] = $results[3];
				}	
			}
			
			if ($token[0] == J_IDENTIFIER) {
				if ( preg_match("/moveTo/", $token[1])) {
					$expected = "X";
					
					switch ($state) {
						case "GRAPHICS":
							$nextGraphics += array("op" => "move");
							break;
						case "CONNECTORS":
							$nextConnector["p1"] = array();
							$currentConnector = "p1";
							break;
						case "SEGMENTS":
							break;
					}
				}
				if ( preg_match("/lineTo/", $token[1])) {
					$expected = "X";
					
					switch ($state) {
						case "GRAPHICS":
							$nextGraphics += array("op" => "line");
							break;
						case "CONNECTORS":
							$nextConnector["p2"] = array();
							$currentConnector = "p2";
							break;
						case "SEGMENTS":
							break;
					}
				}
				if ( preg_match("/bezierCurveTo/", $token[1])) {
					$expected = "C1X";
					
					switch ($state) {
						case "GRAPHICS":
							$nextGraphics += array("op" => "bezier");
							break;
						case "CONNECTORS":
							break;
						case "SEGMENTS":
							break;
					}
				}
				
				if ( preg_match("/beginPath/", $token[1])) {
					$expected = "";
					
					switch ($state) {
						case "GRAPHICS":
							$nextGraphics += array("op" => "startStroke");
							$graphics["graphics"][] = $nextGraphics;
							$nextGraphics = array();
							
							$nextGraphics += array("op" => "startFill");
							$graphics["graphics"][] = $nextGraphics;
							$nextGraphics = array();
							break;
						case "CONNECTORS":
							break;
						case "SEGMENTS":
							break;
					}
				}
			}
			
			if ($token[0] == J_NUMERIC_LITERAL) {
				switch ($expected) {
					case "X" :
						switch ($state) {
							case "GRAPHICS":
								$nextGraphics += array("x" => $token[1]);
								$expected = "Y";
								break;
							case "CONNECTORS":
								$expected = "Y";
								$nextConnector[$currentConnector]["x"] = $token[1];
								break;
							case "SEGMENTS":
								break;
						}
						
						break;
					case "Y" :
						switch ($state) {
							case "GRAPHICS":
								$nextGraphics +=  array("y" => $token[1]);
								$graphics["graphics"][] = $nextGraphics;
								$nextGraphics = array();
								$expected = "";
								
								break;
							case "CONNECTORS":
								$nextConnector[$currentConnector]["y"] = $token[1];
								$expected = "";
								break;
							case "SEGMENTS":
								break;
						}

						break;
					case "C1X" :
						switch ($state) {
							case "GRAPHICS":
								$nextGraphics += array("cp1x" => $token[1]);
								$expected = "C1Y";
								break;
							case "CONNECTORS":
								break;
							case "SEGMENTS":
								$nextSegment["cp1"]["x"] = $token[1];
								$expected = "C1Y";
								break;
						}
						
						break;
					case "C1Y" :
						switch ($state) {
							case "GRAPHICS":
								$nextGraphics += array("cp1y" => $token[1]);
								$expected = "C2X";
								break;
							case "CONNECTORS":
								break;
							case "SEGMENTS":
								$nextSegment["cp1"]["y"] = $token[1];
								$expected = "C2X";
								break;
						}
						
						break;	
					case "C2X" :
						switch ($state) {
							case "GRAPHICS":
								$nextGraphics += array("cp2x" => $token[1]);
								$expected = "C2Y";
								break;
							case "CONNECTORS":
								break;
							case "SEGMENTS":
								$nextSegment["cp2"]["x"] = $token[1];
								$expected = "C2Y";
								break;
						}
						
						break;	
					case "C2Y" :
						switch ($state) {
							case "GRAPHICS":
								$nextGraphics += array("cp2y" => $token[1]);
								$expected = "X";
								break;
							case "CONNECTORS":
								break;
							case "SEGMENTS":
								$nextSegment["cp2"]["y"] = $token[1];
								$expected = "";
								break;
						}
						
						break;					
					default:
						break;		
				}
			}
		}
		
		//Flush previous graphics
		if (sizeof($nextGraphics)>0)
			$graphics["graphics"][] = $nextGraphics;
			
		//Flush previous connector
		if (sizeof($nextConnector)>0)
			$connectors["connectors"][] = $nextConnector;
			
		//Flush previous segments
		if (sizeof($nextSegment)>0)
			$segments["segments"][] = $nextSegment;
	
		/*
		$jgraphics = json_encode($graphics);
		log_message('error', $jgraphics);
		
		$jconnectors = json_encode($connectors);
		log_message('error', $jconnectors);
		*/
		return ($geo+$graphics+$connectors+$segments);
	}
    
	public function index($page = 0) {

		$this->load->library('pagination');
        $config['base_url']   = site_url('admin/templates/index/') ;
        $config['total_rows'] = $this->template_model->count_all();
        $config['per_page']   = 10;    // if you change this you must also change the crud call below.

        $this->pagination->initialize($config);
        $table_data['pagination'] = $this->pagination->create_links();

   		$result = $this->template_model->list_paginated($config['per_page'], $page);
        
        $this->load->library('table');
		$tmpl = array ( 'table_open'  => '<table class="table table-bordered table-striped">' );

        $this->table->set_template($tmpl);
        $this->table->set_heading('ID','Name','Edit','Delete','View'); 	

        
        foreach($result as $entry) {
        
        	$id = $entry['_id']->{'$id'};
        
        	$this->table->add_row(
                        $id, 
                        $entry['name'], 
                        anchor('admin/templates/edit/'.$id,'Edit'),
                        anchor('admin/templates/delete/'.$id,'Delete'),
                        anchor('admin/templates/display/'.$id, 'View'));
        }
        
        //Generate table if at least one template
        if ($this->template_model->count_all()>0) {
        	$table_data['content'] = $this->table->generate();
        } else {
        	$table_data['content'] = "";
        }
        
        //Add "New" button
        $table_data['content'].= '<a class="btn btn-small btn-info" href="'.site_url('admin/templates/add/').'"><i class="icon-plus icon-white"></i> Add new template</a>';
        
        $table_data['messages'] = $this->messages->get();

        
        $layout_data['content'] = $this->load->view('admin/templates/list', $table_data, true);
		
		$navigation_data['activeTab'] = "templates";
		
		$layout_data['pageTitle'] = "Tracks";
		$layout_data['pageDescription'] = "";
		$layout_data['nav_bar'] = $this->load->view('admin/common/navigation', $navigation_data, true);

		$this->load->view('admin/layouts/main', $layout_data);

	}
	
	public function add() {
        // Load Helpers as needed.
        $this->load->helper(array('form', 'url'));
        // Load Libraries as needed.
        $this->load->library('form_validation');
        
        //uploads
        $config['upload_path'] = './uploads/';
		$config['allowed_types'] = 'html';
		$config['file_name'] = 'track.html';
		$config['is_image'] = 0;
		$config['overwrite'] = TRUE;

		$this->load->library('upload', $config);
        
        // Rules Here
        $this->form_validation->set_rules('name', 'Name', 'required');
        
        // Check to see if form passed validation rules
        if ($this->form_validation->run() == FALSE)
        {
            // Load the form as a var 
            $display['content'] = $this->load->view('admin/templates/add', '', TRUE);

            // Display the final output.
        	$layout_data['content'] = $display['content'];
            		
		 	$navigation_data['activeTab'] = "templates";
		
	     	$layout_data['pageTitle'] = "Tracks";
	     	$layout_data['pageDescription'] = "";
	     	$layout_data['nav_bar'] = $this->load->view('admin/common/navigation', $navigation_data, true);

	     	$this->load->view('admin/layouts/main', $layout_data);
        } 
        else
        {
        	// If the form passed validation
            $data = array( "name" => $this->input->post('name'),
            			   "vendor" => $this->input->post('vendor'),
            			   "reference" => $this->input->post('reference'),
            			   "influence" => $this->input->post('influence')
            			   );
			
			if ($this->input->post('illustrator')) {
            	$data_illustrator = $this->parse_illustrator($this->input->post('illustrator'));
            	$data += $data_illustrator;
            } else {
            	if ( ! $this->upload->do_upload()) {
					$error = array('error' => $this->upload->display_errors());
				} else {
					$data_from_file = file_get_contents($config['upload_path'].$config['file_name']);
            		$data_illustrator = $this->parse_illustrator($data_from_file);
            		$data += $data_illustrator;
				}
            }

            $this->template_model->create($data);	
			$this->messages->add("Added a new track, ".$this->input->post('name'), "success");
                        
            // Return to the index.
            redirect(site_url('admin/templates/'));
        }
	}
	
	public function edit($id = 0) {
		$this->load->helper(array('form', 'url'));
		$this->load->library('form_validation');
		
		//Have we been submitted ?
		
		if ($this->input->post('update') !== FALSE) {
			// Rules Here
            $this->form_validation->set_rules('id', 'ID', 'required');
            $this->form_validation->set_rules('name', 'Name', 'required|max_length[255]');       
			
			// Check to see if form passed validation rules
            if ($this->form_validation->run() == FALSE)
            {
                // Load the form
                $form['id'] = $id;
                $form['name'] = $this->input->post('name');
                $display['content'] = $this->load->view('admin/templates/edit', $form, TRUE);
            } else    {
            	$data = array( "name" => $this->input->post('name'),
            				   "vendor" => $this->input->post('vendor'),
            				   "reference" => $this->input->post('reference'),
            				   "influence" => $this->input->post('influence'),
            				   "connectors" => $this->input->post('connectors'));
            	
            	
            	$this->template_model->update( $id, $data);	
            	
            	$this->messages->add("Updated the track, ".$this->input->post('name'), "success");

                // We are done updating, return to the index.
                redirect(site_url('admin/templates/'));
            }            
		
		} else {
			// Not submitted yet
			$query = $this->template_model->get_by_id($id);
			
			// process the query like a normal CI Query.
            if (count($query) > 0) {
                $row             = $query[0];
                $form['id']      = $row["_id"];
                $form['name'] 	 = $row["name"];
                $form['vendor']  	= isset($row["vendor"]) ? $row["vendor"] : "";
                $form['reference']  = isset($row["reference"]) ? $row["reference"] : "";
                $form['regX']  		= isset($row["regX"]) ? $row["regX"] : "";
                $form['regY']  		= isset($row["regY"]) ? $row["regY"] : "";
                $form['influence']  = isset($row["influence"]) ? $row["influence"] : "";
                $form['connectors'] = isset($row["connectors"]) ? $row["connectors"] : "";

                
                // Save the form as "content"
                $display['content'] = $this->load->view('admin/templates/edit', $form, TRUE);
            } 
            else 
            {
                // if we couldn't find the id... tell them there was a problem.
                $display['content'] = 'This track does not exist.';
            }

  		}
  		
  		 // Display the final output.
         $layout_data['content'] = $display['content'];
            		
		 $navigation_data['activeTab'] = "templates";
		
	     $layout_data['pageTitle'] = "Tracks";
	     $layout_data['pageDescription'] = "";
	     $layout_data['nav_bar'] = $this->load->view('admin/common/navigation', $navigation_data, true);

	     $this->load->view('admin/layouts/main', $layout_data);
	}
	
	public function delete($id = 0) {
		$this->template_model->delete_by_id($id);
		redirect(site_url('admin/templates/'));
	}
}
?>