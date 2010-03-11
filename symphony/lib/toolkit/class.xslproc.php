<?php

	Class XSLProcException extends Exception{
		private $_error;
		
		public function getType(){
			return $this->_error->type;
		}
		
		public function __construct($message){
			parent::__construct($message);
			$this->_error = NULL;

			$errors = XSLProc::getErrors();
			
			foreach($errors as $e){

				if($e->type == XSLProc::ERROR_XML){
					$this->_error = $errors[0];
					$this->file = XSLProc::lastXML();
					$this->line = $this->_error->line;
					return;
				}
				elseif(strlen(trim($e->file)) == 0) continue;

				$this->_error = $errors[0];
				
				$this->file = $this->_error->file;
				$this->line = $this->_error->line;
				break;
			}
			
			if(is_null($this->_error)){
				foreach($errors as $e){
					if(preg_match_all('/(\/?[^\/\s]+\/.+.xsl) line (\d+)/i', $e->message, $matches, PREG_SET_ORDER)){
						$this->file = $matches[0][1];
						$this->line = $matches[0][2];
						break;
					}
					
					elseif(preg_match_all('/([^:]+): (.+) line (\d+)/i', $e->message, $matches, PREG_SET_ORDER)){
						$this->line = $matches[0][3];
						$page = Symphony::parent()->Page()->pageData();
						$this->file = VIEWS . '/' . $page['filelocation'];
					}
				}
			}
		}
	}
	
	Class XSLProcExceptionHandler extends GenericExceptionHandler{
		
		protected static function __nearbyLines($line, $file, $isString=false, $window=5){
			if($isString === false) return array_slice(file($file), max(0, ($line - 1) - $window), $window*2, true);
			return array_slice(preg_split('/[\r\n]+/', $file), max(0, ($line - 1) - $window), $window*2, true);
			
		}
		
		public static function render($e){
			
			$lines = NULL;
			$odd = true;

			$markdown .= "\t" . $e->getMessage() . "\n";
			$markdown .= "\t" . $e->getFile() . " line " . $e->getLine() . "\n\n";

			foreach(self::__nearByLines($e->getLine(), $e->getFile(), $e->getType() == XSLProc::ERROR_XML, 11) as $line => $string){
				
				$markdown .= "\t" . ($line+1) . "\t" . htmlspecialchars($string);
				
				// Make sure there is at least 1 tab at the beginning.
				if(strlen(trim($string)) > 0){
					$string = "\t{$string}";
				}

				$lines .= sprintf(
					'<li%s%s><strong>%d:</strong> <code>%s</code></li>', 
					($odd == true ? ' class="odd"' : NULL),
					(($line+1) == $e->getLine() ? ' id="error"' : NULL),
					++$line, 
					str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', htmlspecialchars($string))
				);

				$odd = !$odd;
			}

			$processing_errors = NULL;
			$odd = true;

			foreach(XSLProc::getErrors() as $error){
				$error->file = str_replace(WORKSPACE . '/', NULL, $error->file);
				
				$processing_errors .= sprintf(
					'<li%s><code>%s %s</code></li>', 
					($odd == true ? ' class="odd"' : NULL),
					(strlen(trim($error->file)) == 0 ? NULL : "<span class=\"important\">[{$error->file}:{$error->line}]</span> "),
					preg_replace('/([^:]+):/', '<span class="important">$1:</span>', htmlspecialchars($error->message))
				);
				$odd = !$odd;
			}

			return sprintf(file_get_contents(TEMPLATE . '/exception.xsl.txt'),
				URL,
				'XSLT Processing Error',
				$e->getMessage(), 
				$e->getLine(),
				($e->getType() == XSLProc::ERROR_XML ? 'XML' : $e->getFile()), 
				$markdown,
				$lines,
				$processing_errors
			);

		}
	}

	Final Class XSLProc{
	
		const ERROR_XML = 1;
		const ERROR_XSL = 2;
		
		const DOC = 3;
		const XML = 4;
	
		static private $_errorLog;
		
		static private $_lastXML;
		static private $_lastXSL;
		
		public static function lastXML(){
			return self::$_lastXML;
		}
		
		public static function lastXSL(){
			return self::$_lastXSL;
		}
		
		public static function isXSLTProcessorAvailable(){
			return (class_exists('XSLTProcessor'));
		}
		
		static private function __processLibXMLerrors($type=self::ERROR_XML){
			if(!is_array(self::$_errorLog)) self::$_errorLog = array();

			foreach(libxml_get_errors() as $error){
				$error->type = $type;
				self::$_errorLog[] = $error;
			}

			libxml_clear_errors();
		}
	
		public static function tidyDocument(DOMDocument $xml){

			$result = XSLProc::transform($xml, 
				'<?xml version="1.0" encoding="UTF-8"?>
				<xsl:stylesheet version="1.0"
				  xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

				<xsl:output method="xml" indent="yes" />

				<xsl:strip-space elements="*"/>

				<xsl:template match="node() | @*">
					<xsl:copy>
						<xsl:apply-templates select="node() | @*"/>
					</xsl:copy>
				</xsl:template>

				</xsl:stylesheet>', XSLProc::DOC);

			$result->preserveWhitespace = true;
			$result->formatOutput = true;

			return $result;

		}

		
		static public function transform($xml, $xsl, $output=self::XML, array $parameters=array(), array $register_functions=array()){
			self::$_lastXML = $xml;
			self::$_lastXSL = $xsl;
			
			self::$_errorLog = array();

			libxml_use_internal_errors(true);
			
			$XMLDoc = new DOMDocument;
			$XMLDoc->loadXML($xml);
			self::__processLibXMLerrors(self::ERROR_XML);

			$XSLDoc = new DOMDocument;
			$XSLDoc->loadXML($xsl);

			if(!self::hasErrors() && $XSLDoc instanceof DOMDocument && $XMLDoc instanceof DOMDocument){
				$XSLProc = new XSLTProcessor;
				if(!empty($register_functions)) $XSLProc->registerPHPFunctions($register_functions);
				$XSLProc->importStyleSheet($XSLDoc);

				if(is_array($parameters) && !empty($parameters)) $XSLProc->setParameter('', $parameters);

				self::__processLibXMLerrors(self::ERROR_XSL);

				if(!self::hasErrors()){
					$result = $XSLProc->{'transformTo'.($output==self::XML ? 'XML' : 'Doc')}($XMLDoc);
					self::__processLibXMLerrors(self::ERROR_XML);
				}
			}
			
			return $result;
		}
	
		static public function hasErrors(){
			return (is_array(self::$_errorLog) && !empty(self::$_errorLog));
		}
	
		static public function getErrors(){
			return self::$_errorLog;
		}
	
	}

/*
	static $processErrors = array();
   
	function trapXMLError($errno, $errstr, $errfile, $errline, $errcontext, $ret=false){
		
		global $processErrors;
		
		if($ret === true) return $processErrors;
		
		$tag = 'DOMDocument::';
		$processErrors[] = array('type' => 'xml', 'number' => $errno, 'message' => str_replace($tag, NULL, $errstr), 'file' => $errfile, 'line' => $errline);
	}
	
	function trapXSLError($errno, $errstr, $errfile, $errline, $errcontext, $ret=false){
		
		global $processErrors;
		
		if($ret === true) return $processErrors;
		
		$tag = 'DOMDocument::';
		$processErrors[] = array('type' => 'xsl', 'number' => $errno, 'message' => str_replace($tag, NULL, $errstr), 'file' => $errfile, 'line' => $errline);
	}	

	Class XsltProcess{
	
		private $_xml;
		private $_xsl;
		private $_errors;
		
		function __construct($xml=null, $xsl=null){
			
			if(!self::isXSLTProcessorAvailable()) return false;
			
			$this->_xml = $xml;
			$this->_xsl = $xsl;
			
			$this->_errors = array();
			
			return true;
			
		}
		
		public static function isXSLTProcessorAvailable(){
			return (class_exists('XsltProcessor') || function_exists('xslt_process'));
		}
		
		private function __process($XSLProc, $xml_arg, $xsl_arg, $xslcontainer = null, $args = null, $params = null) {
		                         
			// Start with preparing the arguments
			$xml_arg = str_replace('arg:', '', $xml_arg);
			$xsl_arg = str_replace('arg:', '', $xsl_arg);
			
			// Create instances of the DomDocument class
			$xml = new DomDocument;
			$xsl = new DomDocument;	     
			 
			// Set up error handling					
			if(function_exists('ini_set')){
				$ehOLD = ini_set('html_errors', false);
			}	
				
			// Load the xml document
			set_error_handler('trapXMLError');	
			$xml->loadXML($args[$xml_arg]);
			
			// Must restore the error handler to avoid problems
			restore_error_handler();
			
			// Load the xml document
			set_error_handler('trapXSLError');	
			$xsl->loadXML($args[$xsl_arg]);

			// Load the xsl template
			$XSLProc->importStyleSheet($xsl);
			
			// Set parameters when defined
			if ($params) {
				General::flattenArray($params);
				
				foreach ($params as $param => $value) {
					$XSLProc->setParameter('', $param, $value);
				}
			}
			
			restore_error_handler();
			
			// Start the transformation
			set_error_handler('trapXMLError');	
			$processed = $XSLProc->transformToXML($xml);

			// Restore error handling
			if(function_exists('ini_set') && isset($ehOLD)){
				ini_set('html_errors', $ehOLD);
			}
			
			restore_error_handler();	
				
			// Put the result in a file when specified
			if($xslcontainer) return @file_put_contents($xslcontainer, $processed);	
			else return $processed;
			
		}	
		
		public function process($xml=null, $xsl=null, array $parameters=array(), array $register_functions=array()){

			global $processErrors;
			
			$processErrors = array();
			
			if($xml) $this->_xml = $xml;
			if($xsl) $this->_xsl = $xsl;
			
			$xml = trim($xml);
			$xsl = trim($xsl);
			
			if(!self::isXSLTProcessorAvailable()) return false; //dont let process continue if no xsl functionality exists
			
			$arguments = array(
		   		'/_xml' => $this->_xml,
		   		'/_xsl' => $this->_xsl
			);
			
			$XSLProc = new XsltProcessor;
			
			if(!empty($register_functions)) $XSLProc->registerPHPFunctions($register_functions);
				
			$result = @$this->__process(
			   $XSLProc,
			   'arg:/_xml',
			   'arg:/_xsl',
			   null,
			   $arguments,
			   $parameters
			);	
				
			while($error = @array_shift($processErrors)) $this->__error($error['number'], $error['message'], $error['type'], $error['line']);
			
			unset($XSLProc);
			
			return $result;		
		}
		
		private function __error($number, $message, $type=NULL, $line=NULL){
			
			$context = NULL;
			
			if($type == 'xml') $context = $this->_xml;
			if($type == 'xsl') $context = $this->_xsl;
			
			$this->_errors[] = array(
									'number' => $number, 
									'message' => $message, 
									'type' => $type, 
									'line' => $line,
									'context' => $context);		
		}
		
		public function isErrors(){		
			return (!empty($this->_errors) ? true : false);				
		}
		
		public function getError($all=false, $rewind=false){
			if($rewind) reset($this->_errors);
			return ($all ? $this->_errors : each($this->_errors));				
		}
		
	}

*/