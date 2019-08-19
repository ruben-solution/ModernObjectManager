<?php
/*
****************************************************************************************************
SysDbObjectMgr.class.php
****************************************************************************************************
Version 1.4
Reto Grütter
****************************************************************************************************
Version 1.5
Ueberarbeitet von Thuri
****************************************************************************************************
Beschreibung:
-------------
kreiert aus Tabellen Objekte

Initialisierung z.B. mit:
$DbObjMgr = new SysDbObjMgr();

Verwendung z.B. mit:
$DbObjMgr->get(....)

Fehlerhandling:
es werden keine eigenen Fehlermeldungen generiert oder zurückgegeben.
In der Testphase kann über Config eingestellt werden, ob die PHP-Errors und die ezSQL-Erros ausgegeben werden ($showErrors).


Benötigt:
---------
=> $SysDatabaseMgr
=> $SysSessionMgr

=> Tabellen, welche damit verknüpft werden, sollten die folgenden Felder beinhalten
   - id
   - unique_key
   - create_tstp
   - modify_tstp
   - created_by
   - modified_by

****************************************************************************************************
Funktionen (alle public):
-------------------------

- create($table=,array(variable=>,variable=>))
- get($table,array('id'=> ...,'key'=> ..,'query'=>...))
- copy($table,$origkey,array(...)
- save()
- delete($obj) [korrekter Aufruf ist $dasObjekt = $DbObjMgr->delete($dasObjekt)]

Private Funktionen:
-------------------
- verifyObj($obj): überprüft, ob es sich um ein Objekt handelt welches von dieser Klasse erstellt wurde


****************************************************************************************************
todo:
****************************************************************************************************

****************************************************************************************************
History:

-- Version 1.0       Reto Grütter         15.01.2013
-- Version 1.2       Reto Grütter         07.10.2013
-- Version 1.3       Reto Grütter         19.06.2015

Anpassungen 1.1
---------------
- utf8-codiert
- reduzierte Version mit nur den verwendeten und getesteten Funktionen

Anpassungen 1.2
---------------
- mit Prepared Statements
- nach save eines neues Objektes wird dieses so vorbereitet, dass danach gleich wieder upgedatet
  werden kann
- neu copy-Funktion

Anpassungen 1.3
---------------
- neuen Parameter quoting eingefügt: verhindert das automatische Ersetzung von " mit &quot;

Anpassungen 1.4
---------------
- get-Funktion neu auch mit ID

Anpassungen 1.5
---------------
- save auch mit mysql-reservierten feld Bezeicnung z.B "alter"

****************************************************************************************************
*/
class SysDbObjectMgr implements ObjectManagerInterface {

  function get($table='',$temparray='',$quoting='') {
    /* Tabelle muss gesetzt sein, im Array können die id, ein key für das Feld unique_key oder ein query mitgegeben werden */

    global $DbMgr;
    global $secret_salt;

    if($table > '') {
      if((isset($temparray['key']) && $temparray['key'] > 0) or (isset($temparray['id']) && $temparray['id'] > 0)) {
        if(isset($temparray['key']) && $temparray['key'] > 0) {
            $params[':key'] = stripslashes($temparray['key']);
            $query = "select * from $table where unique_key = :key";
        } else if(isset($temparray['id']) && $temparray['id'] > 0) {
            $params[':id'] = stripslashes($temparray['id']);
            $query = "select * from $table where id = :id";
        }
        if($myobj = $DbMgr->getPrepRow($query,$params)) {
            foreach($myobj as $k => $v) {
                      if($quoting == '') {
                        $v = str_replace('"','&quot;',$v);
                      } else if($quoting == '1') {
                        $v = htmlspecialchars($v);
                      }
              $myobj->$k = stripslashes($v); /* die Feldwerte stripslashes */
                    }
          $myobj->sysTable = $table;
          $myobj->sysSecKey = hash('sha256', $myobj->id.'_'.$table.'_'.$secret_salt);
          return $myobj;
        }
      } else {
        return FALSE;
      }
    } else {
      return FALSE;
    }
  }

    function copy($table='',$origkey='',$varray='',$quoting='') {
    /* Tabelle muss gesetzt sein, wird ein key für das Feld unique_key des Originaleintrages mitgegeben */

      global $DbMgr;
    global $secret_salt;
    if($table > '') {
      if($origkey > 0) {
        $params[':key'] = stripslashes($origkey);
                $query = "select * from $table where unique_key = :key";
        if($myobj = $DbMgr->getPrepRow($query,$params)) {
            foreach($myobj as $k => $v) {
                      if($quoting == '') {
    				      $v = str_replace('"','&quot;',$v);
                      } else if($quoting == '1') {
                        $v = htmlspecialchars($v);
                      } else if($quoting == '2') {
                        $v = $v;
                      }
              $myobj->$k = stripslashes($v); /* die Feldwerte stripslashes */
                    }
          // System-Defaults
    				$myobj->id = 0;
                    $myobj->unique_key = '';
    				$myobj->sysTempId = uniqid();
    				$myobj->sysTable = $table;
    				$myobj->sysSecKey = hash('sha256', $myobj->sysTempId.'_'.$table.'_'.$secret_salt);

                    // und noch allfällige Initialisierungswerte abfüllen: nur, falls das Attribut auch tatsächlich existiert
    				if(is_array($varray)) {
    					foreach($varray as $k => $v) {
    						if(property_exists($myobj, $k)) {
                                if($quoting == '') {
    						        $v = str_replace('"','&quot;',$v);
                                } else if($quoting == '1') {
                                    $v = htmlspecialchars($v);
                                }
    							$myobj->$k = stripslashes($v);
    						} else {
    							$donothing = TRUE;
    						}
    					}
    				}

          return $myobj;
        }
      } else {
        return FALSE;
      }
    } else {
      return FALSE;
    }
  }

  function create($table='',$varray='',$quoting='') {
    /* Tabelle muss gesetzt sein, im Array können Initialisierungswerte mitgegeben werden */

    global $DbMgr;
    global $secret_salt;


    if($table > '') {
      // einlesen der Tabellenfelder
      if($cols = $DbMgr->getResults("show columns from $table")) {

        $temparray = array();

        foreach($cols as $c) {
          $fieldname = $c->Field;
          $temparray[$fieldname] = '';
        }

        $myobj = (object) $temparray;

        // System-Defaults
        $myobj->id = 0;
        $myobj->sysTempId = uniqid();
        $myobj->sysTable = $table;
        $myobj->sysSecKey = hash('sha256', $myobj->sysTempId.'_'.$table.'_'.$secret_salt);

        // und noch allfällige Initialisierungswerte abfüllen: nur, falls das Attribut auch tatsächlich existiert
        if(is_array($varray)) {
          foreach($varray as $k => $v) {
            if(property_exists($myobj, $k)) {
                            if($quoting == '') {
								/* warum das??  wegen der anzeige!! */
    						    $v = str_replace('"','&quot;',$v);
                            } else if($quoting == '1') {
                                $v = htmlspecialchars($v);
                            }
              $myobj->$k = stripslashes($v);
            } else {
              $donothing = TRUE;
            }
          }
        }

        return $myobj;

      } else {
        return FALSE;
      }
    } else {
      return FALSE;
    }

  }

  function remove($obj) {
    global $DbMgr;

    if($this->verifyObj($obj)) {
      if($obj->id > 0) {
                $params[':id'] = $obj->id;
                $query = "delete from $obj->sysTable where id = :id";
                $DbMgr->prepQuery($query,$params);
      }

      // und noch komplett löschen: korrekter Aufruf ist $dasObjekt = $DbObjMgr->delete($dasObjekt) mit Rückgabe eines leeren Arrays
      unset($obj);
      return True;
    } else {
      return False;
    }
  }

  function save($obj,$old=0) {

    global $DbMgr;
    global $SessionMgr;
    global $SysMgr;
    global $secret_salt;


    $adminuser = $SessionMgr->getVar("adminuser");


    if($this->verifyObj($obj)) {
            //Systemfelder entfernen:
            $mytable = $obj->sysTable;
            unset($obj->sysTempId);
            unset($obj->sysTable);
            unset($obj->sysSecKey);

      if($obj->id > 0) {
        // Update
                $obj->modify_tstp = date('Y-m-d H:i:s');
                if($adminuser->id > 0) $obj->modified_by = $adminuser->id;

                $querystring = "update `".$mytable."` set ";
                $querystring2 = "update `".$mytable."` set ";
                $params = array();
                foreach($obj as $k => $v) {
                  $querystring .= " `".$k."` = :".$k.",";
                  $querystring2 .= " `".$k."` = '".addslashes($v)."',";
                  $params[':'.$k] = $v;
                }
                $querystring = substr($querystring,0,-1);
                $querystring2 = substr($querystring2,0,-1);
                $querystring .= " where `id` = ".$obj->id;
                $querystring2 .= " where `id` = ".$obj->id;
                if($old == 1) {
                    $DbMgr->query($querystring2);
                } else {
                    $DbMgr->prepQuery($querystring,$params);
                }

                $obj->sysTable = $mytable;
                $obj->sysSecKey = hash('sha256', $obj->id.'_'.$mytable.'_'.$secret_salt);
                return $obj->id;

      } else {

    		    unset($obj->id);
        // oder Insert
                $obj->create_tstp = date('Y-m-d H:i:s');
                if($adminuser->id > 0) $obj->created_by = $adminuser->id;
                $querystring = "insert into `".$mytable."` (";
                $querystring2 = "insert into `".$mytable."` (";
                $params = array();
                foreach($obj as $k => $v) {
                  $querystring .= " `".$k."`,";
                  $querystring2 .= " `".$k."`,";
                }
                $querystring = substr($querystring,0,-1);
                $querystring2 = substr($querystring2,0,-1);
                $querystring .= ") values(";
                $querystring2 .= ") values(";
                foreach($obj as $k => $v) {
                  $querystring .= " :".$k.",";
                  $querystring2 .= " '".addslashes($v)."',";
                  $params[':'.$k] = $v;
                }
                $querystring = substr($querystring,0,-1);
                $querystring2 = substr($querystring2,0,-1);
                $querystring .= ")";
                $querystring2 .= ")";

                if($old == 1) {
                    $DbMgr->query($querystring2);
                    $tempid = $DbMgr->insert_id;
                } else {
                    $DbMgr->prepQuery($querystring,$params);
                    $tempid = $DbMgr->prepLastInsertId();
                }

                // nun noch den Key generieren
                $unique_key = $tempid."_". $this->makeKey(12);
                $DbMgr->query("update ".$mytable." set unique_key = '$unique_key' where id = $tempid");

        // und nun noch vorbereiten für weitere Updates
                $obj->id = $tempid;
                $obj->sysTable = $mytable;
                $obj->unique_key = $unique_key;
                $obj->sysSecKey = hash('sha256', $obj->id.'_'.$mytable.'_'.$secret_salt);
                return $tempid;
      }
    } else {
      return FALSE;
    }

  }

  private function verifyObj($obj) {
    global $secret_salt;

    // überprüft, ob es sich um ein von dieser Klasse erstelltes Objekt handelt:
    // ...ist es ein Objekt?
    if(is_object($obj)) {

      // ...sind alle System-Attribute vorhanden?
      if(property_exists($obj, 'id') and property_exists($obj, 'sysTable') and property_exists($obj, 'sysSecKey')) {
        // ...der sysSecKey muss übereinstimmen
        if($obj->id > 0) {
          $checkKey = hash('sha256', $obj->id.'_'.$obj->sysTable.'_'.$secret_salt);
        } else {
          $checkKey = hash('sha256', $obj->sysTempId.'_'.$obj->sysTable.'_'.$secret_salt);
        }
        if($checkKey == $obj->sysSecKey) {
          return TRUE;
        } else {
          return FALSE;
        }
      } else {
        return FALSE;
      }
    } else {
      return FALSE;
    }
  }



    public function makeKey($len=8,$format='upper') {
		// $len = Länge des Keys
		// $format = 'upper' oder 'lower'
		mt_srand((double)microtime() * 1000000);
		$key = '';
		for($i = 0; $i < $len ; $i++) {
			$num = mt_rand(48, 122);
			if (($num > 96 && $num < 123 ) || ($num > 64 && $num < 91) || ($num > 47 && $num < 58)){
				$key .= chr($num);
			}else{
				$i--;
			}
		}
		if($format == 'upper') {
			return strtoupper($key);
		} else {
			return strtolower($key);
		}
	}

}

?>