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

class UUID
{
  const MATCH_STRING = "/[[:xdigit:]]{8}-[[:xdigit:]]{4}-[[:xdigit:]]{4}-[[:xdigit:]]{4}-[[:xdigit:]]{12}/";

  public static function validate(string $uuid): bool
  {
    return preg_match(self::MATCH_STRING, "$uuid") ? true : false;
  }
 
  public static function binary_pack(string $uuid): ?string
  {
    if(!self::validate($uuid))
      return null;
      
    return hex2bin(str_replace('-', '', $uuid));
  }

  public static function binary_unpack(string $uuid): ?string
  {
    if(strlen($uuid) != 16)
      return null;

    $hex = bin2hex($uuid);
    $uuid = substr($hex,  0, 8)."-".
            substr($hex,  8, 4)."-".
            substr($hex, 12, 4)."-".
            substr($hex, 16, 4)."-".
            substr($hex, 20);

    return $uuid;
  }
};
