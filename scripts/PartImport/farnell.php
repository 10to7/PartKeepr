<?php
include('simple_html_dom.php');
require("config.php");

//mysql_connect($db_config['server'],$db_config['username'],$db_config['password']) or die(mysql_error());
//mysql_select_db($db_config['database']) or die(mysql_error());

$conn = new mysqli($db_config['server'], $db_config['username'], $db_config['password'], $db_config['database']);

if ($conn->connect_error) {
  trigger_error('Database connection failed: '  . $conn->connect_error, E_USER_ERROR);
}


error_reporting(E_ALL);
ini_set("display_errors", 1);

$sku = htmlspecialchars($_GET["sku"]);

if(!is_numeric($sku))
{
  echo "enter a valid sku value";
}

$importer = new FarnellUKPartImport;
$part = $importer->getPart($sku);
print_r($part);
$importer->addPart($part, $conn);

$conn->close();

class FarnellUKPartImport {

        public function getPart($orderid) {
		$array = false;
		$url = 'http://uk.farnell.com/jsp/search/productdetail.jsp?SKU=' . $orderid;	
		$html = file_get_html($url);

		$detailsContainer = $html->find('#pddetailsContainer', 0);
		$detailsNode = $detailsContainer->find('.pd_details',0);
		$detailsArray = self::getAdjacentElementArray('dt', $detailsNode);

		// Get each attibute pair
		$attributeArray = false;
		foreach($html->find('span.prodAttrName') as $e)
		{
			$attribBlock = $e->parent();
			$attribName = $attribBlock->find('span.prodAttrName',0)->innertext;
  			$attribVal = $attribBlock->find('span.prodAttrValue',0)->innertext;
			$attributeArray[self::cleankey($attribName)] = $attribVal;
		}

		$array['datasheet'] =  array_pop($detailsArray);
                $array['manufacturer'] = $detailsArray["Manufacturer"];
                $array['distributer'] = 'Farnell';
                $array['ordercode'] = $detailsArray['Order Code'];
                $array['name'] = $detailsArray['Manufacturer Part No'];
                $array['description'] = $html->find('meta[name=description]',0)->getAttribute('content');

		$validUnits = array('V', 'C', 'A', 'F');
		$validPowers = array('m', 'p', 'n', 'u', 'μ', 'G', 'M', 'k', 'T');
		$validAttributes = false;

		//find the footprint.
		foreach($attributeArray as $k => $v) {
			if(strpos($k, 'Case') !== FALSE) {
				$array['footprint'] = $v;
			}
			$unit = $v[strlen($v)-1];
			$value = substr($v, 0, strlen($v)-1); 
			$value = str_replace('°', '', $value);
			$isValid = false;	
			$power = '';
			if(in_array($unit, $validUnits)) {
				if(is_numeric($value)) {
					$isValid = true;
				} else {
					$power = $value[strlen($value)-1];
					$value = substr($value, 0, strlen($value)-1);
					if(is_numeric($value)) {
						$isValid = true;
					}  
				}
			}
			if($isValid) {
				$validAttributes[$k] = array($value, $power, $unit);
			}
		}
		$array['attributes'] = $validAttributes;
		return $array;
        }

	public function addPart($part, $conn) {
		$query_addPart = "INSERT INTO Part (category_id, footprint_id, name, description, comment, stockLevel, minStockLevel, averagePrice, status, needsReview, partCondition, createDate, internalPartNumber, partUnit_id, storageLocation_id) VALUES (1,?,?,?,?,0,0,0,'',1,'',NOW(),'',1,11)";	
		$footprintid = $this->getFootPrintId($part['footprint'], $conn);
		$stmt = $conn->prepare($query_addPart);
		$stmt->bind_param('ssss',$footprintid, $part['name'], $part['description'], $part['datasheet']);
		$stmt->execute();
		$partid = $conn->insert_id;
		$stmt->close();
		$this->addPartParameters($partid, $part, $conn);
		$this->addPartDistributor($partid, $part, $conn);
		return $partid;
	}

	public function addPartParameters($partid, $part, $conn) {
		// going to cheat on the unit_id and the siPrefix_id, check against your own values.
		$unit_id = array('V' => 9, 'C' => 21, 'A' => 7, 'F'=> 16);
		$siPrefix_id = array('m' => 14, 'p' => 17, 'n' => 9, 'u' => 15, 'μ' => 15, 'G' =>6, 'M' => 7, 'k' => 8, 'T' =>5);

		foreach($part['attributes'] as $k => $v) {
			$query_insert = "INSERT INTO PartParameter (part_id, unit_id, name, description, value, rawValue, siPrefix_id) VALUES (?,?,?,'',?,?,?)";
                        $stmt = $conn->prepare($query_insert);
                        if($stmt == false) {
                                trigger_error('Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
                        }
                        $stmt->bind_param('iisddi', $partid, $unit_id[$v[2]], $k, $v[0], $v[0], $siPrefix_id[$v[1]]);
                        $stmt->execute();
                        $result = $conn->insert_id;
                        $stmt->close();
		}		
	}

	public function addPartDistributor($partid, $part, $conn) {
		$query_insert = "INSERT INTO PartDistributor (part_id, distributor_id, orderNumber, packagingUnit, price, sku) VALUES (?,1,'',1,0.000,?)";
                $stmt = $conn->prepare($query_insert);
                if($stmt == false) {
                	trigger_error('Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
                }
                $stmt->bind_param('ii', $partid, $part['ordercode']);
                $stmt->execute();
                $result = $conn->insert_id;
                $stmt->close();
	} 

	public function getFootPrintId($footprintName, $conn) {
		$result = 0;
		$query_select = 'SELECT id FROM Footprint WHERE name = ?';
		$stmt = $conn->prepare($query_select);
		if($stmt == false) {
			trigger_error('Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
		}
		$stmt->bind_param('s', $footprintName);
		$stmt->execute();
		$stmt->bind_result($id);
		while($stmt->fetch()) {	
			$result = $id;
		}
		$stmt->close();

		if($result == 0) {
			$query_insert = "INSERT INTO Footprint (category_id, name, description) VALUES (9, ?, '')";
			$stmt = $conn->prepare($query_insert);
			if($stmt == false) {
                        	trigger_error('Wrong SQL: ' . $sql . ' Error: ' . $conn->error, E_USER_ERROR);
                	}
			$stmt->bind_param('s', $footprintName);
			$stmt->execute();
			$result = $conn->insert_id;
			$stmt->close();
		}

		return $result;
	}

	protected static function getAdjacentElementArray($searchElement, $node)
	{ 
		$array = false; 
		foreach($node->find($searchElement) as $e)
		{
			$key = $e->innertext;
			$links = $e->nextSibling()->find('a', 0);
			if ($links) {
				$value = $links->href;
			} else {
				$value = $e->nextSibling()->innertext;
			}
			$array[self::cleankey($key)] = $value;
		} 
  		return $array; 
	} 

	protected static function cleanKey($value)
	{
		return rtrim(trim($value),":");
	}
}
?>
