<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin is used to access Pod videos
 *
 * @since Moodle 3.3
 * @package    repository_pod
 * @copyright  2017 Obled Joel
 * @copyright  2019 Mifsud Gaël
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * repository_pod class
 * This is a class used to browse videos from Pod
 *
 * @since Moodle 3.3
 * @package    repository_pod
 * @copyright  2017 Obled Joelo
 * @copyright  2019 Mifsud Gaël
 * @licence    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require 'vendor/autoload.php';
use Elasticsearch\ClientBuilder;


class repository_pod extends repository {

	public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
		parent::__construct($repositoryid, $context, $options);
	}

	// Functions for configurations in administration //

	public static function get_type_option_names() {
		return array('es_domain', 'es_port', 'pluginname');
	}

	public static function type_config_form($mform, $classname = 'repository') {
		parent::type_config_form($mform);

		$mform->addElement('text', 'es_domain', get_string('esdomain', 'repository_pod'));
		$mform->addRule('es_domain', get_string('required'), 'required', null, 'client');
		$mform->setType('es_domain', PARAM_RAW);
		$mform->setDefault('es_domain', 'pod.univ.fr');
		

		$mform->addElement('text', 'es_port', get_string('esport', 'repository_pod'));
		$mform->addRule('es_port', get_string('required'), 'required', null, 'client');
		$mform->setType('es_port', PARAM_RAW);
		$mform->setDefault('es_port', 9200);
		
	}

	public static function type_form_validation($mform, $data, $errors) {
		if (preg_match_all('/:([0-9]+)/', $data['es_domain'], $out) > 0) {
			$errors['es_domain'] = get_string('invaliddomain', 'repository_pod');
		}

		if (preg_match_all('/(http|https|ftp):\/\//', $data['es_domain'], $out) > 0) {
			$errors['es_domain'] = get_string('invaliddomain', 'repository_pod');
		}

		if (!is_numeric($data['es_port'])) {
			$errors['es_port'] = get_string('invalidport', 'repository_pod');
		}

		return $errors;
	}

	// Functions for search form //

	public function check_login() {
		global $SESSION;
		$this->keyword = optional_param('pod_keyword', '', PARAM_RAW);
		if (empty($this->keyword)) {
			$this->keyword = optional_param('s', '', PARAM_RAW);
		}
		$sess_keyword = 'pod_'.$this->id.'_keyword';
		if (empty($this->keyword) && optional_param('page', '', PARAM_RAW)) {
			if (isset($SESSION->{$sess_keyword})) {
				$this->keyword = $SESSION->{$sess_keyword};
			}
		}else if (!empty($this->keyword)) {
			$SESSION->{$sess_keyword} = $this->keyword;
		}

		return !empty($this->keyword);
	}

	public function print_login() {
		$keyword = new stdClass();
		$keyword->label = get_string('keyword', 'repository_pod').': ';
		$keyword->id 	= 'input_text_keyword';
		$keyword->type 	= 'text';
		$keyword->name 	= 'pod_keyword';
		$keyword->value = '';

		$start_date = new stdClass();
		$start_date->label = get_string('startdate', 'repository_pod').': ';
		$start_date->id    = 'input_date_startdate';
		$start_date->type  = 'date';
		$start_date->name  = 'pod_startdate';

		$end_date = new stdClass();
		$end_date->label   = get_string('enddate', 'repository_pod').': ';
		$end_date->id 	   = 'input_date_enddate';
		$end_date->type    = 'date';
		$end_date->name    = 'pod_enddate';

		$size = new stdClass();
		$size->label 	   = get_string('size', 'repository_pod').': ';
		$size->id 		   = 'input_size';
		$size->type 	   = 'text';
		$size->name        = 'pod_size';
		$size->value       = 12;

		if ($this->options['ajax']) {
			$form = array();
			$form['login'] = array($keyword, $start_date, $end_date, $size);
			$form['nosearch'] = true;
			$form['norefresh'] = true;
			$form['logouttext'] = get_string('back', 'repository_pod');
			$form['allowcaching'] = false;
			return $form;
		} else {
			echo <<<EOD
<table>
<tr>
<td>{$keyword->label}</td><td><input name="{$keyword->name}" type="text" /></td>
<td>{$start_date->label}</td><td><input name="{$start_date->name}" type="date" /></td>
<td>{$end_date->label}</td><td><input name="{$end_date->name}" type="date" /></td>
<td>{$size->label}</td><td><input name="{$size->name}" type="text" /></td>
</tr>
</table>
<input type="submit" />
EOD;
		}
	}

	public function logout() {
		return $this->print_login();
	}

	// Function for search operations //

	public function init_elastic($domain, $port) {

		$hosts = [
			$domain . ':' . $port
		];

		$client = ClientBuilder::create()
							->setHosts($hosts)
							->build();

		return $client;
	}

	public function global_search() {
		return false;
	}

	public function search($search_text, $page = 0) {
		$client = $this->init_elastic(get_config('pod', 'es_domain'), get_config('pod', 'es_port'));
		$startdate = optional_param('pod_startdate', '', PARAM_TEXT);
		$enddate = optional_param('pod_enddate', '', PARAM_TEXT);
		$size = optional_param('pod_size', 10, PARAM_TEXT);
		$search_results = array();

		$query = ['match_all' => array()];
		if ($search_text != '') {
			$query = [
				'multi_match' => [
					'query' => $search_text,
					'fields' => ["_id", "title^1.1", "owner^0.9", "owner_full_name^0.9", "description^0.6", "tags.name^1",
	                	"contributors^0.6", "chapters.title^0.5", "enrichments.title^0.5", "type.title^0.6", "disciplines.title^0.6", "channels.title^0.6"
	                ]
				]
			];
		}

		if (!is_numeric($size)) {
			$size = 12;
		}

		$filterdate = array();
		$filterdate['range'] = ['date_added' => array()];
		if ($startdate != '') {
			$filterdate['range']['date_added']['gte'] = $startdate;
		}
		if ($enddate != '') {
			$filterdate['range']['date_added']['lte'] = $enddate;
		}

		$params = [
			'body' => [
				'from' => $page * $size,
				'size' => $size,
				'query' => [
					'function_score' => [
						'query' => array(),
						'functions' => [
							array(
								'gauss' => [
									'date_added' => [
										'scale' => '10d',
										'offset' => '5d',
										'decay' => 0.5
									]	
								]	
							)
						]
					]
				]
			]
		];

		if ($startdate != '' or $enddate != '') {
			$params['body']['query']['function_score']['query'] = ['filtered' => array()];
			$params['body']['query']['function_score']['query']['filtered']['query'] = $query;
			$params['body']['query']['function_score']['query']['filtered']['filter'] = $filterdate;
		} else {
			$params['body']['query']['function_score']['query'] = $query;
		}

		$query_results = $client->search($params);

		foreach($query_results['hits'] as $url) {
			foreach($url as $source) {
				$search_results[] = [
					'shortitle' => $source['_source']['title'],
					'title' => $source['_source']['title'].'.mp4',
					'source' => $source['_source']['full_url'],
					'datecreated' => strtotime($source['_source']['date_added']),
					'author' => $source['_source']['owner_full_name'],
					'size' => '',
					'thumbnail' => $source['_source']['thumbnail'],
					'thumbnail_width' => 120,
					'thumbnail_height' => 80	
				];
			}	
		}

		return $search_results;
	}

	// Function for search result rendering //

	public function get_listing($path = '', $page = '') {
		$list = array();

		$list['page'] = (int)$page;
		if ($list['page'] < 1) {
			$list['page'] = 1;
		}

		$list['list'] = $this->search($this->keyword, $list['page'] - 1);

		$list['nosearch'] = true;
		$list['norefresh'] = true;
		$list['logouttext'] = get_string('back', 'repository_pod');

		if (!empty($list['list'])) {
			$list['pages'] = -1;
		}else if ($list['page'] > 1) {
			$list['pages'] = $list['page'];
		}else {
			$list['pages'] = 0;
		}

		return $list;
    }

    // Parameters for the repository //
	
	public function supported_returntypes() {
    	return FILE_EXTERNAL;
    }
}