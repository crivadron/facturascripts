<?php
/*
 * This file is part of FacturaSctipts
 * Copyright (C) 2014  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/// Definimos algunas constantes
if( !defined('FS_NF0') )
   define('FS_NF0', 2);
if( !defined('FS_NF1') )
   define('FS_NF1', '.');
if( !defined('FS_NF2') )
   define('FS_NF2', ' ');

require_once 'base/bround.php';
require_once 'base/fs_cache.php';

if(strtolower(FS_DB_TYPE) == 'mysql')
   require_once 'base/fs_mysql.php';
else
   require_once 'base/fs_postgresql.php';

require_once 'base/fs_default_items.php';


/*
 * Esta función sirve para cargar modelos, y sobre todo, para cargarlos
 * desde la carpeta plugins, así se puede personalizar aún más el comportamiento
 * de FacturaScripts.
 */
function require_model($name)
{
   if( !isset($GLOBALS['plugins']) )
   {
      /// Cargamos la lista de plugins activos
      $GLOBALS['plugins'] = array();
      if( file_exists('tmp/enabled_plugins') )
      {
         foreach(scandir('tmp/enabled_plugins') as $f)
         {
            if( is_string($f) AND strlen($f) > 0 AND !is_dir($f) )
            {
               if( file_exists('plugins/'.$f) )
                  $GLOBALS['plugins'][] = $f;
               else
                  unlink('tmp/enabled_plugins/'.$f);
            }
         }
      }
   }
   
   if( !isset($GLOBALS['models']) )
      $GLOBALS['models'] = array();
   
   if( !in_array($name, $GLOBALS['models']) )
   {
      /// primero buscamos en los plugins
      $found = FALSE;
      foreach($GLOBALS['plugins'] as $plugin)
      {
         if( file_exists('plugins/'.$plugin.'/model/'.$name) )
         {
            require_once 'plugins/'.$plugin.'/model/'.$name;
            $found = TRUE;
            break;
         }
      }
      
      if( !$found )
         require_once 'model/'.$name;
      
      $GLOBALS['models'][] = $name;
   }
}


abstract class fs_model
{
   protected $db;
   protected $table_name;
   protected $base_dir;
   protected $cache;
   protected $default_items;
   
   private static $checked_tables;
   private static $errors;
   
   public function __construct($name = '', $basedir = '')
   {
      if(strtolower(FS_DB_TYPE) == 'mysql')
         $this->db = new fs_mysql();
      else
         $this->db = new fs_postgresql();
      
      $this->table_name = $name;
      $this->base_dir = $basedir;
      $this->cache = new fs_cache();
      $this->default_items = new fs_default_items();
      
      if( !self::$errors )
         self::$errors = array();
      
      if( !self::$checked_tables )
      {
         if( $this->cache->error() )
         {
            self::$checked_tables = array();
         }
         else
         {
            self::$checked_tables = $this->cache->get_array('fs_checked_tables');
            if( self::$checked_tables )
            {
               /// nos aseguramos de que existan todas las tablas que se suponen comprobadas
               $tables = $this->db->list_tables();
               foreach(self::$checked_tables as $ct)
               {
                  if( !$this->db->table_exists($ct, $tables) )
                  {
                     $this->clean_checked_tables();
                     break;
                  }
               }
            }
         }
      }
      
      if($name != '')
      {
         if( !in_array($name, self::$checked_tables) )
         {
            if( $this->check_table($name) )
            {
               self::$checked_tables[] = $name;
               $this->cache->set('fs_checked_tables', self::$checked_tables);
            }
         }
      }
   }
   
   protected function clean_checked_tables()
   {
      self::$checked_tables = array();
      $this->cache->delete('fs_checked_tables');
   }
   
   protected function new_error_msg($msg = FALSE)
   {
      if( $msg )
         self::$errors[] = $msg;
   }
   
   public function get_errors()
   {
      return self::$errors;
   }
   
   /*
    * Esta función es llamada al crear una tabla.
    * Permite insertar tuplas o lo que desees.
    */
   abstract protected function install();
   
   /*
    * Esta función devuelve TRUE si los datos del objeto se encuentran
    * en la base de datos.
    */
   abstract public function exists();
   
   /// Esta función devuelve TRUE si los datos del objeto son válidos
   abstract public function test();
   
   /*
    * Esta función sirve tanto para insertar como para actualizar
    * los datos del objeto en la base de datos.
    */
   abstract public function save();
   
   /// Esta función sirve para eliminar los datos del objeto de la base de datos
   abstract public function delete();
   
   public function escape_string($s='')
   {
      return $this->db->escape_string($s);
   }
   
   public function var2str($v)
   {
      if( is_null($v) )
         return 'NULL';
      else if( is_bool($v) )
      {
         if($v)
            return 'TRUE';
         else
            return 'FALSE';
      }
      else if( preg_match('/^([0-9]{1,2})-([0-9]{1,2})-([0-9]{4})$/i', $v) ) /// es una fecha
         return "'".Date($this->db->date_style(), strtotime($v))."'";
      else if( preg_match('/^([0-9]{1,2})-([0-9]{1,2})-([0-9]{4}) ([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})$/i', $v) ) /// es una fecha+hora
         return "'".Date($this->db->date_style().' H:i:s', strtotime($v))."'";
      else
         return "'" . $this->db->escape_string($v) . "'";
   }
   
   public function bin2str($v)
   {
      if( is_null($v) )
         return 'NULL';
      else
         return "'".bin2hex($v)."'";
   }
   
   public function hex2bin($data)
   {
      $bin = '';
      $i = 0;
      do {
         $bin .= chr(hexdec($data{$i}.$data{($i + 1)}));
         $i += 2;
      } while($i < strlen($data));
      return $bin;
   }
   
   public function str2bin($v)
   {
      if( is_null($v) )
         return NULL;
      else
         return $this->hex2bin($v);
   }
   
   public function str2bool($v)
   {
      return ($v == 't' OR $v == '1');
   }
   
   public function intval($s)
   {
      if( is_null($s) )
         return NULL;
      else
         return intval($s);
   }
   
   /*
    * Compara dos números en coma flotante con una precisión de $precision,
    * devuelve TRUE si son iguales, FALSE en caso contrario.
    */
   public function floatcmp($f1, $f2, $precision = 10, $round = FALSE)
   {
      if($round)
         return( abs($f1-$f2) < 6/pow(10,$precision+1) );
      else
         return( bccomp( (string)$f1, (string)$f2, $precision ) == 0 );
   }
   
   /*
    * Compara tres números en coma flotante usando la función floatcmp().
    * ATENCIÓN: NO COPARA LOS TRES NÚMERO ENTRE SI, solamente compara el
    * primero con los otros dos.
    * Devuelve TRUE si el primer número es igual a alguno de los otros dos.
    */
   public function floatcmp3($f1, $f2, $f3, $precision = 10, $round = FALSE)
   {
      if( $this->floatcmp($f1, $f2, $precision, $round) )
         return TRUE;
      else if( $this->floatcmp($f1, $f3, $precision, $round) )
         return TRUE;
      else
         return FALSE;
   }
   
   /// functión auxiliar para facilitar el uso de fechas
   public function var2timesince($v)
   {
      if( isset($v) )
      {
         $v = strtotime($v);
         $time = time() - $v;
         
         if($time <= 60)
            return 'hace '.round($time/60,0).' segundos';
         else if(60 < $time && $time <= 3600)
            return 'hace '.round($time/60,0).' minutos';
         else if(3600 < $time && $time <= 86400)
            return 'hace '.round($time/3600,0).' horas';
         else if(86400 < $time && $time <= 604800)
            return 'hace '.round($time/86400,0).' dias';
         else if(604800 < $time && $time <= 2592000)
            return 'hace '.round($time/604800,0).' semanas';
         else if(2592000 < $time && $time <= 29030400)
            return 'hace '.round($time/2592000,0).' meses';
         else if($time > 29030400)
            return 'hace más de un año';
      }
      else
         return 'fecha desconocida';
   }
   
   public function date_range($first, $last, $step = '+1 day', $format = 'd-m-Y' )
   {
      $dates = array();
      $current = strtotime($first);
      $last = strtotime($last);
      
      while( $current <= $last )
      {
         $dates[] = date($format, $current);
         $current = strtotime($step, $current);
      }
      
      return $dates;
   }
   
   /*
    * Esta función convierte:
    * < en &lt;
    * > en &gt;
    * " en &quot;
    * ' en &#39;
    * 
    * No tengas la tentación de sustiturla por htmlentities o htmlspecialshars
    * porque te encontrarás con muchas sorpresas desagradables.
    */
   public function no_html($t)
   {
      $newt = str_replace('<', '&lt;', $t);
      $newt = str_replace('>', '&gt;', $newt);
      $newt = str_replace('"', '&quot;', $newt);
      $newt = str_replace("'", '&#39;', $newt);
      return trim($newt);
   }
   
   public function random_string($length = 10)
   {
      return mb_substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"),
              0, $length);
   }
   
   /// comprueba y actualiza la estructura de la tabla si es necesario
   public function check_table($table_name)
   {
      $done = TRUE;
      $consulta = '';
      $columnas = FALSE;
      $restricciones = FALSE;
      $xml_columnas = FALSE;
      $xml_restricciones = FALSE;
      
      if( $this->get_xml_table($table_name, $xml_columnas, $xml_restricciones) )
      {
         if( $this->db->table_exists($table_name) )
         {
            /// comparamos las columnas
            $columnas = $this->db->get_columns($table_name);
            $consulta .= $this->db->compare_columns($table_name, $xml_columnas, $columnas);
            
            /// comparamos las restricciones
            $restricciones = $this->db->get_constraints($table_name);
            $consulta .= $this->db->compare_constraints($table_name, $xml_restricciones, $restricciones);
         }
         else
         {
            /// generamos el sql para crear la tabla
            $consulta .= $this->db->generate_table($table_name, $xml_columnas, $xml_restricciones);
            $consulta .= $this->install();
         }
         
         if($consulta != '')
         {
            if( !$this->db->exec($consulta) )
            {
               $this->new_error_msg('Error al comprobar la tabla '.$table_name);
               $done = FALSE;
            }
         }
      }
      else
      {
         $this->new_error_msg('Error con el xml.');
         $done = FALSE;
      }
      
      return $done;
   }
   
   /// obtiene las columnas y restricciones del fichero xml para una tabla
   protected function get_xml_table($table_name, &$columnas, &$restricciones)
   {
      $retorno = FALSE;
      $filename = $this->base_dir.'model/table/'.$table_name.'.xml';
      
      if( file_exists($filename) )
      {
         $xml = simplexml_load_file($filename);
         if($xml)
         {
            if($xml->columna)
            {
               $i = 0;
               foreach($xml->columna as $col)
               {
                  $columnas[$i]['nombre'] = $col->nombre;
                  $columnas[$i]['tipo'] = $col->tipo;
                  $columnas[$i]['nulo'] = $col->nulo;
                  
                  if($col->defecto == '')
                     $columnas[$i]['defecto'] = NULL;
                  else
                     $columnas[$i]['defecto'] = $col->defecto;
                  
                  $i++;
               }
               
               /// debe de haber columnas, sino es un fallo
               $retorno = TRUE;
            }
            
            if($xml->restriccion)
            {
               $i = 0;
               foreach($xml->restriccion as $col)
               {
                  $restricciones[$i]['nombre'] = $col->nombre;
                  $restricciones[$i]['consulta'] = $col->consulta;
                  $i++;
               }
            }
         }
         else
            $this->new_error_msg('Error al leer el archivo '.$filename);
      }
      else
         $this->new_error_msg('Archivo '.$filename.' no encontrado.');
      
      return $retorno;
   }
}

?>