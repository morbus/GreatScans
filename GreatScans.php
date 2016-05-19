<?php

/**
 * @file
 * GreatScans.php
 *
 * @todo INSERT CATCHY ONE-LINER HERE.
 * Copyright (C) 2016 Morbus Iff <morbus@disobey.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

set_time_limit(0);

$cmd_line_opts = [
  'short' => 'h::',
  'long' => [
    'help::',
  ],
];

$passed_opts = getopt($cmd_line_opts['short'], $cmd_line_opts['long']);

if (isset($passed_opts['h']) || isset($passed_opts['help'])) {
  print "@todo\n";
}

// @todo You should. You know. Maybe write this.
