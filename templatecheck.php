<?php

/**
 *  templatecheck -- tool to validate template invocations
 *  Copyright (C) 2015 Tim Landscheidt
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// TODO:
// check length.

error_reporting (E_ALL);

$config = parse_ini_file ('../replica.my.cnf', true);
$toolserver_username = trim ($config ['client'] ['user'], "'");
$toolserver_password = trim ($config ['client'] ['password'], "'");

function register_article_error($article, $type, $parameter) {
    global $ArticleErrors;

    if (!array_key_exists ($article, $ArticleErrors)) {
        $ArticleErrors [$article] = array();
    }
    if (!array_key_exists ($type, $ArticleErrors [$article])) {
        $ArticleErrors [$article] [$type] = array();
    }
    if (!array_key_exists ($parameter, $ArticleErrors [$article] [$type])) {
        $ArticleErrors [$article] [$type] [$parameter] = 0;
    }
    $ArticleErrors [$article] [$type] [$parameter]++;
}

function register_article_error_value($article, $type, $parameter, $value) {
    global $ArticleErrors;

    if (!array_key_exists ($article, $ArticleErrors)) {
        $ArticleErrors [$article] = array();
    }
    if (!array_key_exists ($type, $ArticleErrors [$article])) {
        $ArticleErrors [$article] [$type] = array();
    }
    if (!array_key_exists ($parameter, $ArticleErrors [$article] [$type])) {
        $ArticleErrors [$article] [$type] [$parameter] = array();
    }
    if (!array_key_exists ($value, $ArticleErrors [$article] [$type] [$parameter])) {
        $ArticleErrors [$article] [$type] [$parameter] [$value] = array();
    }
    $ArticleErrors [$article] [$type] [$parameter] [$value]++;
}

// http://php.net/manual/en/function.urlencode.php
// 9:40 tal
if (isset ($_GET ["template"]))   // @@TODO@@ Müsste noch auf Sanity geprüft werden!
  {
    mysql_connect ('tools.labsdb', $toolserver_username, $toolserver_password) or die (mysql_error ());
    mysql_select_db ('s51071__templatetiger_p') or die (mysql_error ());

    $ArticleErrors = array ();
    $Parameters = array ();
    ini_set ("user_agent", "templatecheck 0.1 (http://toolserver.org/~timl/templatecheck.php)");
    $xml = simplexml_load_file ("http://de.wikipedia.org/w/index.php?title=Template:" . urlencode ($_GET ["template"]) . "/XML&action=raw") or die ("Couldn't read XML");
    if (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"])
      {
        header ("Content-Type: text/html; charset=utf8");
        echo ("<html>\n");
        echo ("  <head>\n");
        echo ("    <title>Übersicht für " . htmlspecialchars ($_GET ["template"]) . "</title>\n");
        echo ("  </head>\n");
        echo ("\n");
        echo ("  <body>\n");
        echo ("    <h1>Übersicht für <a href=\"http://de.wikipedia.org/wiki/Template:" . urlencode ($_GET ["template"]) . "\">" . htmlspecialchars ($_GET ["template"]) . "</a></h1>\n");
        echo ("    <ul>\n");
      }
    else
      header ("Content-Type: text/plain; charset=utf8");

    foreach ($xml->Group as $Group)
      {
        foreach ($Group->Parameter as $p)
          {
            if ($p ['null'] == 'false')
              {
                $result = mysql_query ("SELECT DISTINCT name FROM dewiki WHERE " .
                                       "tp_name = '" . mysql_escape_string ($_GET ["template"]) . "' AND " .
                                       "(entry_name = '" . mysql_escape_string ($p ['name']) . "' AND REPLACE(Value, ' ', '') = '' OR " .
                                       "NOT EXISTS (SELECT 't' FROM dewiki AS t2 " .
                                       "WHERE dewiki.name = t2.name AND " .
                                       "dewiki.tp_name = t2.tp_name AND t2.entry_name = '" . mysql_escape_string ($p ['name']) . "'))") or die (mysql_error ());
                while ($r = mysql_fetch_row ($result))
                  register_article_error($r [0], 'notnull', (string) $p ['name']);
              }
            if (isset ($p->Value))
              {
                $LegalValues = array ();
                foreach ($p->Value as $v)
                  $LegalValues [] = "'" . mysql_escape_string ($v) . "'";
                $result = mysql_query ("SELECT DISTINCT name, Value FROM dewiki WHERE " .
                                       "tp_name = '" . mysql_escape_string ($_GET ["template"]) . "' AND " .
                                       "entry_name = '" . mysql_escape_string ($p ['name']) . "' AND " .
                                       "REPLACE(Value, ' ', '') <> '' AND Value NOT IN (" . join (', ', $LegalValues) . ')') or die (mysql_error ());
                while ($r = mysql_fetch_row ($result))
                  register_article_error_value($r [0], 'values', (string) $p ['name'], $r [1]);
              }
            if (isset ($p->Condition))
              {
                $result = mysql_query ("SELECT DISTINCT name, Value FROM dewiki WHERE tp_name = '" . mysql_escape_string ($_GET ["template"]) . "' AND entry_name = '" . mysql_escape_string ($p ['name']) . "' AND REPLACE(Value, ' ', '') <> '' AND Value NOT REGEXP (REPLACE (REPLACE (REPLACE ('" . mysql_escape_string ($p->Condition) . "', '[\\\\d]', '[0-9]'), '\\\\d', '[0-9]'), '*?', '*'))") or die (mysql_error ());
                while ($r = mysql_fetch_row ($result))
                  register_article_error_value($r [0], 'conds', (string) $p ['name'], $r [1]);
              }
            $Parameters [] = mysql_escape_string ($p ['name']);
          }
      }
    $result = mysql_query ("SELECT DISTINCT name, entry_name FROM dewiki WHERE tp_name = '" . mysql_escape_string ($_GET ["template"]) . "' AND entry_name NOT IN ('" . join ("', '", $Parameters) . "')") or die (mysql_error ());
    while ($r = mysql_fetch_row ($result))
      register_article_error($r [0], 'unknown', $r [1]);
    ksort ($ArticleErrors);
    foreach ($ArticleErrors as $Article => &$Value)
      {
        echo (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? ("<li><a href=\"http://de.wikipedia.org/wiki/" . htmlspecialchars ($Article) . "\">" . htmlspecialchars ($Article) . "</a>:\n  <ul>\n") : ("* [[" . $Article . "]]:\n"));
        if (isset ($Value ['unknown']))   // Unbekannte Parameter
          {
            ksort ($Value ['unknown']);
            echo ((!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "<li>" : "** ") . "Unbekannte Parameter: " . join (', ', array_keys ($Value ['unknown'])) . (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "</li>\n" : "\n"));
          }
        if (isset ($Value ['notnull']))   // Leerer/nicht vorhandene Parameter
          {
            ksort ($Value ['notnull']);
            echo ((!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "<li>" : "** ") . "Leerer/nicht vorhandener Parameter: " . join (', ', array_keys ($Value ['notnull'])) . (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "</li>\n" : "\n"));
          }
        if (isset ($Value ['values']))   // Unbekannte Parameter
          {
            $list = '';
            ksort ($Value ['values']);
            foreach ($Value ['values'] as $k1 => &$v1)
              {
                if ($list != '')
                  $list .= ', ';
                ksort ($v1);
                $list .= $k1 . ' (' . join (', ', array_keys ($v1)) . ')';
              }
            echo ((!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "<li>" : "** ") . "Nicht erlaubter Parameter (Auswahlliste): " . $list . (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "</li>\n" : "\n"));
          }
        if (isset ($Value ['conds']))   // Unbekannte Parameter
          {
            $list = '';
            ksort ($Value ['conds']);
            foreach ($Value ['conds'] as $k1 => &$v1)
              {
                if ($list != '')
                  $list .= ', ';
                ksort ($v1);
                $list .= $k1 . ' (' . join (', ', array_keys ($v1)) . ')';
              }
            echo ((!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "<li>" : "** ") . "Nicht erlaubter Parameter (regulärer Ausdruck): " . $list . (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? "</li>\n" : "\n"));
          }
        echo (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"] ? ("  </ul>\n") : (""));
      }
    if (!array_key_exists('wikiformat', $_GET) || !$_GET ["wikiformat"])
      {
        echo ("    </ul>\n");
        echo ("    <p>Fertig.</p>\n");
        echo ("  </body>\n");
        echo ("</html>");
      }
  }
else
  {
    mysql_connect ('dewiki.labsdb', $toolserver_username, $toolserver_password) or die (mysql_error ());
    mysql_select_db ('dewiki_p') or die (mysql_error ());
    $query = "SELECT REPLACE(SUBSTRING(page_title FROM 1 FOR LENGTH(page_title) - 4), '_', ' ') AS Template FROM categorylinks JOIN page ON cl_from = page_id WHERE cl_to = 'Vorlage:für_Vorlagen-Meister' AND page_namespace = 10 ORDER BY page_title;";
    $result = mysql_query ($query) or die (mysql_error ());

    if (!$result)
      die ("ERROR: No result returned." . mysql_error ());

    header ("Content-Type: text/html; charset=utf8");
    echo ("<html><head><title>Vorlagenprüfung</title></head><body>\n");
    echo ("<form method=\"get\">\n");
    echo ("<select name=\"template\" size=\"1\">\n");
    while ($row = mysql_fetch_assoc ($result))
      echo ("<option>" . $row ['Template'] . "</option>\n");
    echo ("</select>\n");
    echo ("<input type=\"checkbox\" name=\"wikiformat\"/> Wiki-Format\n");
    echo ("<input type=\"submit\"/>\n");
    echo ("</form>\n");
    echo ("</body></html>\n");
  }

mysql_close ();
