<?php
/**
 *  Pasthis - Stupid Simple Pastebin
 *
 * Copyright (C) 2014 Julien (jvoisin) Voisin - dustri.org
 * Copyright (C) 2014 Antoine Tenart <atenart@n0.pe>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301, USA.
 */

final class Pasthis {
    public $title;
    private $contents = array ();
    private $db;

    function __construct ($title = 'Pasthis') {
        $this->title = $title;
        $this->db = new SQLite3 ('pasthis.db',
                SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        if (is_null ($this->db)) {
            if (file_exists('pasthis.db'))
                die ("Unable to open database, check permissions");
            else
                die ("Unable to create database, check permissions");
        }
        $this->db->exec ('pragma auto_vacuum = 1');
        $this->db->query (
            "CREATE TABLE if not exists pastes (
                id PRIMARY KEY,
                deletion_date TIMESTAMP,
                paste BLOB
            );"
        );
        $this->db->query (
            "CREATE TABLE if not exists users (
                hash PRIMARY KEY,
                nopaste_period TIMESTAMP,
                degree INTEGER
            );"
        );
    }

    function __destruct () {
        $this->db->close ();
    }

    private function add_content ($content, $prepend = false) {
        if (!$prepend)
            $this->contents[] = $content;
        else
            array_unshift ($this->contents, $content);
    }

    private function render () {
        print '<!DOCTYPE html>';
        print '<html>';
        print '<head>';
        print '<title>'.htmlentities ($this->title).'</title>';
        print '<link href="./css/style.css" rel="stylesheet" type="text/css" />';
        print '<link href="./css/prettify.css" rel="stylesheet" type="text/css" />';
        print '</head>';
        print '<body>';
        while (list (, $ct) = each ($this->contents))
            print $ct;
        print '</body>';
        print '</html>';
        exit ();
    }

    private function remaining_time ($timestamp) {
        if ($timestamp === -1)
            return 'Never expires.';
        elseif ($timestamp == -2)
            return 'One remaining reading.';

        $format = function ($t,$s) { return $t ? $t.' '.$s.($t>1 ? 's' : '' ).' ' : ''; };

        $expiration = new DateTime ('@'.$timestamp);
        $interval = $expiration->diff (new DateTime (), true);

        $ret = 'Expires in '.$format ($interval->y, 'year').$format ($interval->m, 'month');
        if ($interval->y === 0) {
            $ret .= $format ($interval->d, 'day');
            if ($interval->m === 0) {
                $ret .= $format ($interval->h, 'hour');
                if ($interval->d === 0) {
                    $ret .= $format ($interval->i, 'minute');
                    if ($interval->h === 0)
                        $ret .= $format ($interval->s, 'second');
                }
            }
        }
        return rtrim ($ret).'.';
    }

    function prompt_paste () {
        $this->add_content (
            "<form method='post' action='.'>
                <label for='d'>Expiration: </label>
                <select name='d' id='d'>
                    <option value='-2'>burn after reading</option>
                    <option value='600'>10 minutes</option>
                    <option value='3600'>1 hour</option>
                    <option value='86400' selected='selected'>1 day</option>
                    <option value='2678400'>1 month</option>
                    <option value='31536000'>1 year</option>
                    <option value='-1'>eternal</option>
                </select>
                <input type='text' id='ricard' name='ricard'
                        placeholder='Do not fill me!' />
                <input type='submit' value='Send paste'>
                <textarea autofocus required name='p'></textarea>
            </form>"
        );
        $this->add_content ('<script src="./js/textarea.js"></script>');

        $this->render ();
    }

    private function generate_id () {
        do {
            $uniqid = substr (uniqid (), -6);
            $result = $this->db->querySingle (
                "SELECT id FROM pastes
                WHERE id='".$uniqid."';"
            );
        } while (!is_null ($result));

        return $uniqid;
    }

    private function nopaste_period ($degree) {
        return time () + intval (pow ($degree, 2.5));
    }

    private function check_spammer () {
        $hash = sha1 ($_SERVER['REMOTE_ADDR']);

        $result = $this->db->querySingle (
            "SELECT * FROM users
             WHERE hash='".$hash."';",
            true
        );

        $in_period = (!empty ($result) and time () < $result['nopaste_period']);
        $obvious_spam = (!isset ($_POST['ricard']) or !empty ($_POST['ricard']));

        $degree = $in_period ? $result['degree']+1 : ($obvious_spam ? 512 : 1);

        $this->db->exec (
            "INSERT OR REPLACE INTO users
             (hash, nopaste_period, degree)
             VALUES ('".$hash."','".$this->nopaste_period ($degree)."','".$degree."');"
        );

        if ($in_period or $obvious_spam)
            die ('Spam');
    }

    function add_paste ($deletion_date, $paste) {
        $this->check_spammer();

        $paste = SQLite3::escapeString ($paste);
        $deletion_date = intval ($deletion_date);

        if ($deletion_date > 0)
            $deletion_date += time ();

        $uniqid = $this->generate_id ();

        $this->db->query ("INSERT INTO pastes (id, deletion_date, paste)
                VALUES ('".$uniqid."','".$deletion_date."','".$paste."');");

        header ('location: ./'.$uniqid);
    }

    function show_paste ($id, $raw) {
        $id = SQLite3::escapeString ($id);
        $raw = intval ($raw);

        $fail = false;
        $request = $this->db->query ("SELECT * FROM pastes WHERE id='".$id."';");
        if (!($request instanceof Sqlite3Result))
            die ('Unable to perform query on the database');

        $result = $request->fetchArray ();

        if ($result === false) {
            $fail = true;
        } elseif ($result['deletion_date'] < time ()
                and $result['deletion_date'] >= 0) {
            $this->db->exec ("DELETE FROM pastes WHERE id='".$id."';");

            /* do not fail on "burn after reading" pastes */
            if ($result['deletion_date'] != 0)
                $fail = true;
        } elseif ($result['deletion_date'] == -2) {
            $this->db->exec (
                "UPDATE pastes
                 SET deletion_date=0
                 WHERE id='".$id."';"
            );
        }

        if ($fail) {
            $this->add_content ("<p>Meh, no paste for this id :(</p>");
            $this->prompt_paste ();
        } elseif (!$raw) {
            $this->add_content ('<script>window.onload=function(){prettyPrint();}</script>');
            $this->add_content ('<script src="./js/prettify.js"></script>', true);
            $this->add_content ('<div><a href="./'.$id.'@raw">Raw</a> - '.
                                '<a href="./">New paste</a></div>');
            $this->add_content ('<pre class="prettyprint">'.
                    htmlspecialchars ($result['paste']).'</pre>');
            $this->add_content ($this->remaining_time ($result['deletion_date']));
        } else {
            header ("Content-Type: text/plain");
            print $result['paste'];
            exit ();
        }

        $this->render ();
    }

    function cron () {
        $this->db->exec (
            "DELETE FROM pastes
             WHERE deletion_date > 0
             AND strftime ('%s','now') > deletion_date;
             DELETE FROM users
             WHERE strftime ('%s','now') > nopaste_period;"
        );
    }
}

$pastebin = new Pasthis ();

if (isset ($_GET['p']))
    $pastebin->show_paste (str_replace ("@raw", "", $_GET['p']),
            strtolower (substr ($_GET['p'], -4)) == "@raw");
elseif (isset ($_POST['d']) && isset ($_POST['p']))
    $pastebin->add_paste ($_POST['d'], $_POST['p']);
else
    $pastebin->prompt_paste ();
?>
