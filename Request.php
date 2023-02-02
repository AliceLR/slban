<?php declare(strict_types=1);

/* slbans
 * Copyright (C) 2022 Lachesis
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once("SQL.php");
require_once("UUID.php");

class Request
{
  /* jank alert */
  private static function get_uri_array(string $uri): ?array
  {
     /* Remove query string. */
     $uri = explode('?', $uri);
     if(count($uri) < 1 || strlen($uri[0]) < 1)
       return null;
     $uri = $uri[0];

     /* Remove prefixed /. */
     if(strcmp($uri[0], '/'))
       return null;
     $uri = substr($uri, 1);

     /* Tokenize URI. */
     $api = explode("/", $uri);
     if(count($api) < 2 || !$api[1])
       return array("index");
     
     return array_slice($api, 1);
  }

  public static function handle(string $uri, array &$get, array &$post): void
  {
    $uri = self::get_uri_array($uri);
    if(!$uri)
    {
      http_response_code(404);
      return;
    }

    switch($uri[0])
    {
      case "index":
      {
        echo "<html><body><ul>";
        echo "<li>slbans/list - GET - returns a comma separated plaintext list of banned UUIDs. If the first entry is 'reset', UUIDs not in this list should be purged.</li>";
        echo "<li>slbans/list/deleted - GET - returns a comma separated plaintext list of formerly banned UUIDs.</li>";
        echo "<li>slbans/add - POST uuid=X reason=Y - add a text UUID to the ban list.</li>";
        echo "<li>slbans/delete - POST uuid=X - delete a text UUID from the ban list.</li>";
        echo "</ul></body></html>";
        return;
      }

      case "list":
      {
        $sql = SQL::getInstance();
        $sql->exec("CALL cleanup()");

        $deleted = count($uri) == 2 && !strcmp($uri[1], "deleted") ? 1 : 0;
        $comma = '';

        if(!$deleted)
        {
          $res = $sql->query("SELECT value FROM state WHERE setting='reset_list'", SQL::RESULT_1D);
          if(is_array($res) && isset($res['value']) && $res['value'])
          {
            echo "reset";
            $comma = ",";
          }
        }

        $list = $sql->query("SELECT uuid FROM banlist WHERE deleted=?", [ $deleted ], SQL::RESULT_NUM);

        header("Content-Type: text/plain");

        if(is_array($list) && count($list))
        {
          foreach($list as $entry)
          {
            echo $comma . UUID::binary_unpack($entry[0]);
            $comma = ',';
          }
        }
        return;
      }

      case "add":
      {
        $uuid = isset($post['uuid']) ? UUID::binary_pack($post['uuid']) : null;
        $reason = isset($post['reason']) ? $post['reason'] : null;
        if(!$uuid)
        {
          http_response_code(400);
          return;
        }

        $sql = SQL::getInstance();
        $res = $sql->exec("INSERT INTO banlist(modified, uuid, reason) VALUES(NOW(), ?, ?) ".
          "ON DUPLICATE KEY UPDATE modified=VALUES(modified), reason=VALUES(reason), deleted=0", [ $uuid, $reason ]);
        if(!$res)
          http_response_code(500);

        return;
      }
    
      case "delete":
      {
        $uuid = isset($post['uuid']) ? UUID::binary_pack($post['uuid']) : null;
        if(!$uuid)
        {
          http_response_code(400);
          return;
        }
        
        $sql = SQL::getInstance();
        $res = $sql->exec("UPDATE banlist SET deleted=1, modified=NOW() WHERE uuid=?", [ $uuid ]);
        if($res)
          $res = $sql->exec("CALL resetList(?)", [ RESET_LIST_TTL_MINUTES ]);
        if(!$res)
          http_response_code(500);

        return;
      }

      default:
        http_response_code(404);
        return;
    }
  }
};
