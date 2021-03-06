<?php

/*
 @nom: Story
 @auteur: Idleman (idleman@idleman.fr)
 @description:  Représente un scénario avec ses causes de déclenchement et ses effets associés
 */

class Story extends SQLiteEntity{

	public $id,$date,$user,$label,$state,$log;
	protected $TABLE_NAME = 'plugin_story';
	protected $CLASS_NAME = 'Story';
	protected $object_fields = 
	array(
		'id'=>'key',
		'date'=>'string',
		'user'=>'int',
		'label'=>'string',
		'state'=>'int',
		'log'=>'longstring'
	);

	function __construct(){
		parent::__construct();
	}
	
	public static function check(){
		require_once(dirname(__FILE__).'/Cause.class.php');
		
		global $conf;
		
		
		self::out('Vérification des scénarios');
			
		$causeManager = new Cause();

		$storyCauses = $causeManager->loadAll(array());
			
		self::out('Vérification des '.count($storyCauses).'	causes');
		
		
		$sentence = $conf->get('last_sentence','var');
		list($i,$h,$d,$m,$y) = explode('-',date('i-H-d-m-Y'));
		$validCauses = array();
		
		self::out('Dernière phrase énoncée : "'.$sentence.'"');
		self::out('Date actuelle '.$d.'/'.$m.'/'.$y.' '.$h.':'.$i);
		
		foreach($storyCauses as $storyCause){
			self::out("Vérification cause ID : $storyCause->id type : $storyCause->type");
			$values = $storyCause->getValues();
			switch ($storyCause->type){
				case 'listen':
					if($values->value == $sentence){
						$validCauses[$storyCause->story][] = $storyCause;
						self::out("Phrase correspondante, ajout $storyCause->id aux causes valides");
					}else{
						self::out("Phrase non correspondante");
					}
				break;
				case 'time':
						;
						
						if ($storyCause->value != $i.'-'.$h.'-'.$d.'-'.$m.'-'.$y) $validate = false;
						if ((
							($i == $values->minut || $values->minut == '*') && 
							($h == $values->hour || $values->hour == '*') && 
							($d == $values->day || $values->day == '*') && 
							($m == $values->month || $values->month == '*') && 
							($y == $values->year || $values->year == '*')
							)){
								self::out("Date correspondante, ajout $storyCause->id aux causes valides");
								$validCauses[$storyCause->story][] = $storyCause;
							}else{
								self::out("Date non correspondante");
							}
				break;
				case 'readvar':
						if ($conf->get($storyCause->target,'var') == $storyCause->value) {
							$validCauses[$storyCause->story][] = $storyCause;
							self::out("Variable correspondante, ajout $storyCause->id aux causes valides");
						}else{
							self::out("Variable non correspondante");
						}
				break;
			}
		}
	
		self::out("Vérification de la somme des causes pour chaques scénario (".count($validCauses)." valides)");
		
		foreach($validCauses as $story=>$causes){
			self::out("Scénario ID : $story, ".count($causes)." causes valides.");
			if(count($causes) == $causeManager->rowCount(array('story'=>$story))){
				self::out("Execution des effets du Scénario ID : $story");
				self::execute($story);
			}else{
				self::out("Non execution des effets du Scénario ID : $story, nombre de causes insuffisantes");
			}
		}

		$conf->put('last_sentence','','var');
	}
	
	
	public static function parse($value){
		global $conf;
		preg_match_all("/(\{)(.*?)(\})/", $value, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$value = str_replace($match[0],$conf->get($match[2],'var'),$value);
		}
		return $value;
	}
	public static function execute($storyId){
			global $conf;
			
			$story = new self();
			$story = $story->getById($storyId);
			
			require_once(dirname(__FILE__).'/Effect.class.php');
			$effectManager = new Effect();
			
			$effects = $effectManager->loadAll(array('story'=>$story->id),'sort');
			$log = '====== Execution '.date('d/m/Y H:i').'======'.PHP_EOL;
			$log .= count($effects).' effets à executer'.PHP_EOL;
			foreach($effects as $effect){
				$data = $effect->getValues();
			
				$log .= '> Execution de l\'effet "'.$effect->type.'"'.PHP_EOL;
				try{
				switch ($effect->type) {
					case 'command':
						if($data->target=='server'){
							
							$log .= "\tcommande server lancée : ".$data->value.PHP_EOL;
							$return = System::commandSilent($data->value);
							$conf->put('cmd_result',$return,'var');	
						}else{
							$log .= "\tcommande client lancée : ".$data->value.PHP_EOL;
							$cli = new Client();
							$cli->connect();
							$cli->execute(self::parse($data->value));
							$cli->disconnect();
						}
					break;
					case 'image':
							$log .= "\tImage affichée : ".self::parse($data->value).PHP_EOL;
							$cli = new Client();
							$cli->connect();
							$cli->image(self::parse($data->value));
							$cli->disconnect();
					break;
					case 'var':
						$log .= "\tVariable ".$data->var.' définie à : "'.self::parse($data->value).'"'.PHP_EOL;
						$conf->put($data->var,self::parse($data->value),'var');
					break;
					case 'url':
						$log .= "\tExecution url ".$data->var.' définie à : "'.self::parse($data->value).'"'.PHP_EOL;
						$return = @file_get_contents(html_entity_decode(self::parse($data->value)));
						$conf->put('url_result',$return,'var');	
					break;
					case 'gpio':
						$log .= "\tChangement GPIO $data->gpio définie à ".self::parse($data->value).PHP_EOL;
						$pins = explode(',',$data->gpio);
						foreach($pins as $pin)
							Gpio::write($pin,self::parse($data->value),true);
						
					break;
					case 'sleep':
						if(!is_numeric($data->value)) throw new Exception('Pause non numerique, pause annulée');
						$log .= "\tPause de ".self::parse($data->value)." secondes".PHP_EOL;
						sleep(self::parse($data->value));
							
					
					break;
					case 'talk':
						$log .= "\tParole : ".self::parse($data->value).PHP_EOL;
						$cli = new Client();
						$cli->connect();
						$cli->talk(self::parse($data->value));
						$cli->disconnect();
					break;
					case 'story':
						if(!is_numeric($data->value)) throw new Exception('ID scénario non numerique, lancement scénario annulé');
						$log .= 'Execution : '.self::parse($data->value).PHP_EOL;
						self::execute(self::parse($data->value));
					break;
					default:
						$log .= "\tType effet inconnu : ".PHP_EOL;
					break;
				}
				}catch(Exception $e){
					$log .= "\tERREUR : ".$e->getMessage().PHP_EOL;
				}
			}
			
			$story->log = $log;
			$story->save();
	}
	
	public static function out($msg){
		global $_;
		if(!isset($_['mode']) || $_['mode'] != 'verbose') return;
		
		echo '<pre>'.date('d/m/Y H:i:s').' | '.$msg.PHP_EOL;
	}
	
}

?>
