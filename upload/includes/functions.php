<?php

if (!defined('BB_ROOT')) die(basename(__FILE__));

/**
 *  $request_type = 'p' or 'g' (for POST or GET)
 */
function verify_sid ($request_type = 'p', $sid_key_name = 'sid', $die_on_error = true)
{
	global $userdata;

	if (empty($request_type) || empty($sid_key_name))
	{
		trigger_error(__FUNCTION__ .": bad arguments", E_USER_ERROR);
	}

	if ($request_type == 'p')
	{
		$sid =& $_POST[$sid_key_name];
	}
	else
	{
		$sid =& $_GET[$sid_key_name];
	}

	$sid_valid = (!empty($sid) && !empty($userdata['session_id']) && $sid === $userdata['session_id']);

	if (!$sid_valid && $die_on_error)
	{
		bb_die('Invalid sid');
	}

	return $sid_valid;
}

function get_tracks ($type)
{
	static $pattern = '#^a:\d+:{[i:;\d]+}$#';

	switch ($type)
	{
		case 'topic':
			$c_name = COOKIE_TOPIC;
			break;
		case 'forum':
			$c_name = COOKIE_FORUM;
			break;
		default:
			trigger_error(__FUNCTION__ .": invalid type '$type'", E_USER_ERROR);
	}
	$tracks = !empty($_COOKIE[$c_name]) ? @unserialize($_COOKIE[$c_name]) : false;
	return ($tracks) ? $tracks : array();
}

function set_tracks ($cookie_name, &$tracking_ary, $tracks = null, $val = TIMENOW)
{
	global $tracking_topics, $tracking_forums, $user;

	if (IS_GUEST) return;

	$prev_tracking_ary = $tracking_ary;

	if ($tracks)
	{
		if (!is_array($tracks))
		{
			$tracks = array($tracks => $val);
		}
		foreach ($tracks as $key => $val)
		{
			$key = (int) $key;
			$val++;
			$curr_track_val = !empty($tracking_ary[$key]) ? $tracking_ary[$key] : 0;

			if ($val > max($curr_track_val, $user->data['user_lastvisit']))
			{
				$tracking_ary[$key] = $val;
			}
			elseif ($curr_track_val < $user->data['user_lastvisit'])
			{
				unset($tracking_ary[$key]);
			}
		}
	}

	$overflow = count($tracking_topics) + count($tracking_forums) - COOKIE_MAX_TRACKS;

	if ($overflow > 0)
	{
		arsort($tracking_ary);
		for ($i=0; $i < $overflow; $i++)
		{
			array_pop($tracking_ary);
		}
	}

	if (array_diff($tracking_ary, $prev_tracking_ary))
	{
		bb_setcookie($cookie_name, serialize($tracking_ary));
	}
}

function get_last_read ($topic_id = 0, $forum_id = 0)
{
	global $tracking_topics, $tracking_forums, $user;

	$t = isset($tracking_topics[$topic_id]) ? $tracking_topics[$topic_id] : 0;
	$f = isset($tracking_forums[$forum_id]) ? $tracking_forums[$forum_id] : 0;
	return max($t, $f, $user->data['user_lastvisit']);
}

function is_unread ($ref, $topic_id = 0, $forum_id = 0)
{
	return (!IS_GUEST && $ref > get_last_read($topic_id, $forum_id));
}

//
// Ads
//
class ads_common
{
	var $ad_blocks  = array();
	var $active_ads = array();

	/**
	*  Constructor
	*/
	function ads_common ()
	{
		global $bb_cfg;

		$this->ad_blocks  =& $bb_cfg['ad_blocks'];
		$this->active_ads = !empty($bb_cfg['active_ads']) ? unserialize($bb_cfg['active_ads']) : array();
	}

	/**
	*  Get ads to show for each block
	*/
	function get ($block_types)
	{
		$ads = array();

		if ($this->active_ads)
		{
			$block_ids = $this->get_block_ids($block_types);

			if ($ad_ids = $this->get_ad_ids($block_ids))
			{
				$ad_html = $this->get_ads_html();

				foreach ($ad_ids as $block_id => $ad_id)
				{
					$ads[$block_id] =& $ad_html[$ad_id];
				}
			}
		}

		return $ads;
	}

	/**
	*  Get ads html
	*/
	function get_ads_html ()
	{
		global $datastore;
		if (!$ads_html = $datastore->get('ads'))
		{
			$datastore->update('ads');
			$ads_html = $datastore->get('ads');
		}

		return $ads_html;
	}

	/**
	*  Get block_ids for specified block_types
	*/
	function get_block_ids ($block_types)
	{
		$block_ids = array();

		foreach ($block_types as $block_type)
		{
			if ($blocks =& $this->ad_blocks[$block_type])
			{
				$block_ids = array_merge($block_ids, array_keys($blocks));
			}
		}

		return $block_ids;
	}

	/**
	*  Get ad_ids for specified blocks
	*/
	function get_ad_ids ($block_ids)
	{
		$ad_ids = array();

		foreach ($block_ids as $block_id)
		{
			if ($ads =& $this->active_ads[$block_id])
			{
				shuffle($ads);
				$ad_ids[$block_id] = $ads[0];
			}
		}

		return $ad_ids;
	}
}

//
// Auth
//
define('AUTH_LIST_ALL', 0);

// forum's ACL types (phpbb_forums: auth_view, auth_read... values)
define('AUTH_REG',   1);
define('AUTH_ACL',   2);
define('AUTH_ADMIN', 5);

// forum_perm bitfields - backward compatible with auth($type)
define('AUTH_ALL',        0);
define('AUTH_VIEW',       1);
define('AUTH_READ',       2);
define('AUTH_MOD',        3);
define('AUTH_POST',       4);
define('AUTH_REPLY',      5);
define('AUTH_EDIT',       6);
define('AUTH_DELETE',     7);
define('AUTH_STICKY',     8);
define('AUTH_ANNOUNCE',   9);
define('AUTH_VOTE',       10);
define('AUTH_POLLCREATE', 11);
define('AUTH_ATTACH',     12);
define('AUTH_DOWNLOAD',   13);

define('BF_AUTH_MOD', bit2dec(AUTH_MOD));

// When defining user permissions, take into account:
define('UG_PERM_BOTH',       1);  // both user and group
define('UG_PERM_USER_ONLY',  2);  // only personal user permissions
define('UG_PERM_GROUP_ONLY', 3);  // only group permissions

$bf['forum_perm'] = array(
	'auth_view'        => AUTH_VIEW,
	'auth_read'        => AUTH_READ,
	'auth_mod'         => AUTH_MOD,
	'auth_post'        => AUTH_POST,
	'auth_reply'       => AUTH_REPLY,
	'auth_edit'        => AUTH_EDIT,
	'auth_delete'      => AUTH_DELETE,
	'auth_sticky'      => AUTH_STICKY,
	'auth_announce'    => AUTH_ANNOUNCE,
	'auth_vote'        => AUTH_VOTE,
	'auth_pollcreate'  => AUTH_POLLCREATE,
	'auth_attachments' => AUTH_ATTACH,
	'auth_download'    => AUTH_DOWNLOAD,
);

$bf['user_opt'] = array(
	'viewemail'        => 0,  // Показывать e-mail
	'allow_sig'        => 1,  // Запрет на подпись
	'allow_avatar'     => 2,  // Запрет на аватар
	'allow_pm'         => 3,  // Запрет на отправку ЛС
	'allow_viewonline' => 4,  // Скрывать пребывание пользователя
	'notify'           => 5,  // Сообщать об ответах в отслеживаемых темах
	'notify_pm'        => 6,  // Сообщать о новых ЛС
	'allow_passkey'    => 7,  // Запрет на добавление passkey, он же запрет на скачивание торрентов
	'hide_porn_forums' => 8,  // Скрывать pron форумы
	'allow_gallery'    => 9,  // Запрет на использование галереи
	'hide_ads'         => 10, // Запрет на показ рекламы
	'allow_topic'      => 11, // Запрет на создание новых тем
	'allow_post'       => 12, // Запрет на отправку сообщений
	'allow_post_edit'  => 13, // Запрет на редактирование сообщений
	'allow_dls'        => 14, // Запрет на список текущих закачек в профиле
);

function bit2dec ($bit_num)
{
	if (is_array($bit_num))
	{
		$dec = 0;
		foreach ($bit_num as $bit)
		{
			$dec |= (1 << $bit);
		}
		return $dec;
	}
	return (1 << $bit_num);
}

function bf_bit2dec ($bf_array_name, $key)
{
	if (!isset($GLOBALS['bf'][$bf_array_name][$key]))
	{
		trigger_error(__FUNCTION__ .": bitfield '$key' not found", E_USER_ERROR);
	}
	return (1 << $GLOBALS['bf'][$bf_array_name][$key]);
}

function bf ($int, $bf_array_name, $key)
{
	return (bf_bit2dec($bf_array_name, $key) & (int) $int);
}

function setbit (&$int, $bit_num, $on)
{
	return ($on) ? $int |= (1 << $bit_num) : $int &= ~(1 << $bit_num);
}

/*
	$type's accepted (pre-pend with AUTH_):
	VIEW, READ, POST, REPLY, EDIT, DELETE, STICKY, ANNOUNCE, VOTE, POLLCREATE

	Possible options ($type/forum_id combinations):

	* If you include a type and forum_id then a specific lookup will be done and
	the single result returned

	* If you set type to AUTH_ALL and specify a forum_id an array of all auth types
	will be returned

	* If you provide a forum_id a specific lookup on that forum will be done

	* If you set forum_id to AUTH_LIST_ALL and specify a type an array listing the
	results for all forums will be returned

	* If you set forum_id to AUTH_LIST_ALL and type to AUTH_ALL a multidimensional
	array containing the auth permissions for all types and all forums for that
	user is returned

	All results are returned as associative arrays, even when a single auth type is
	specified.

	If available you can send an array (either one or two dimensional) containing the
	forum auth levels, this will prevent the auth function having to do its own
	lookup
*/
function auth ($type, $forum_id, $ug_data, $f_access = array(), $group_perm = UG_PERM_BOTH)
{
	global $lang, $bf, $datastore;

	$is_guest = true;
	$is_admin = false;
	$auth = $auth_fields = $u_access = array();
	$add_auth_type_desc = ($forum_id != AUTH_LIST_ALL);

	//
	// Get $auth_fields
	//
	if ($type == AUTH_ALL)
	{
		$auth_fields = array_keys($bf['forum_perm']);
	}
	else if ($auth_type = array_search($type, $bf['forum_perm']))
	{
		$auth_fields = array($auth_type);
	}

	if (empty($auth_fields))
	{
		trigger_error(__FUNCTION__ .'(): empty $auth_fields', E_USER_ERROR);
	}

	//
	// Get $f_access
	//
	// If f_access has been passed, or auth is needed to return an array of forums
	// then we need to pull the auth information on the given forum (or all forums)
	if (empty($f_access))
	{
		if (!$forums = $datastore->get('cat_forums'))
		{
			$datastore->update('cat_forums');
			$forums = $datastore->get('cat_forums');
		}

		if ($forum_id == AUTH_LIST_ALL)
		{
			$f_access = $forums['f'];
		}
		else if (isset($forums['f'][$forum_id]))
		{
			$f_access[$forum_id] = $forums['f'][$forum_id];
		}
	}
	else if (isset($f_access['forum_id']))
	{
		// Change passed $f_access format for later using in foreach()
		$f_access = array($f_access['forum_id'] => $f_access);
	}

	if (empty($f_access))
	{
		trigger_error(__FUNCTION__ .'(): empty $f_access', E_USER_ERROR);
	}

	//
	// Get user or group permissions
	//
	$forum_match_sql = ($forum_id != AUTH_LIST_ALL) ? "AND aa.forum_id = ". (int) $forum_id : '';

	// GROUP mode
	if (!empty($ug_data['group_id']))
	{
		$is_guest = false;
		$is_admin = false;

		$sql = "SELECT aa.forum_id, aa.forum_perm
			FROM ". BB_AUTH_ACCESS ." aa
			WHERE aa.group_id = ". (int) $ug_data['group_id'] ."
				$forum_match_sql";

		foreach (DB()->fetch_rowset($sql) as $row)
		{
			$u_access[$row['forum_id']] = $row['forum_perm'];
		}
	}
	// USER mode
	else if (!empty($ug_data['user_id']))
	{
		$is_guest = empty($ug_data['session_logged_in']);
		$is_admin = (!$is_guest && $ug_data['user_level'] == ADMIN);

		if ($group_perm != UG_PERM_BOTH)
		{
			$group_single_user = ($group_perm == UG_PERM_USER_ONLY) ? 1 : 0;

			$sql = "
				SELECT
					aa.forum_id, BIT_OR(aa.forum_perm) AS forum_perm
				FROM
					". BB_USER_GROUP  ." ug,
					". BB_GROUPS      ." g,
					". BB_AUTH_ACCESS ." aa
				WHERE
					    ug.user_id = ". (int) $ug_data['user_id'] ."
					AND ug.user_pending = 0
					AND g.group_id = ug.group_id
					AND g.group_single_user = $group_single_user
					AND aa.group_id = g.group_id
						$forum_match_sql
					GROUP BY aa.forum_id
			";

			foreach (DB()->fetch_rowset($sql) as $row)
			{
				$u_access[$row['forum_id']] = $row['forum_perm'];
			}
		}
		else
		{
			if (!$is_guest && !$is_admin)
			{
				$sql = "SELECT SQL_CACHE aa.forum_id, aa.forum_perm
					FROM ". BB_AUTH_ACCESS_SNAP ." aa
					WHERE aa.user_id = ". (int) $ug_data['user_id'] ."
						$forum_match_sql";

				foreach (DB()->fetch_rowset($sql) as $row)
				{
					$u_access[$row['forum_id']] = $row['forum_perm'];
				}
			}
		}
	}

	// If the user is logged on and the forum type is either ALL or REG then the user has access
	//
	// If the type if ACL, MOD or ADMIN then we need to see if the user has specific permissions
	// to do whatever it is they want to do ... to do this we pull relevant information for the
	// user (and any groups they belong to)
	//
	// Now we compare the users access level against the forums. We assume here that a moderator
	// and admin automatically have access to an ACL forum, similarly we assume admins meet an
	// auth requirement of MOD
	//
	foreach ($f_access as $f_id => $f_data)
	{
		$auth[$f_id]['auth_mod'] = auth_check('forum_perm', 'auth_mod', $u_access, $f_id, $is_admin);

		foreach ($auth_fields as $auth_type)
		{
			if (!isset($f_data[$auth_type]))
			{
				continue;
			}
			switch ($f_data[$auth_type])
			{
				case AUTH_ALL:
					$auth[$f_id][$auth_type] = true;
					break;

				case AUTH_REG:
					$auth[$f_id][$auth_type] = !$is_guest;
					break;

				case AUTH_ACL:
					$auth[$f_id][$auth_type] = (auth_check('forum_perm', $auth_type, $u_access, $f_id, $is_admin) || $auth[$f_id]['auth_mod']);
					break;

				case AUTH_MOD:
					$auth[$f_id][$auth_type] = $auth[$f_id]['auth_mod'];
					break;

				case AUTH_ADMIN:
					$auth[$f_id][$auth_type] = $is_admin;
					break;

				default:
					$auth[$f_id][$auth_type] = false;
			}
			if ($add_auth_type_desc)
			{
				$auth[$f_id][$auth_type .'_type'] =& $lang['AUTH_TYPES'][$f_data[$auth_type]];
			}
		}
	}

	return ($forum_id == AUTH_LIST_ALL) ? $auth : $auth[$forum_id];
}

function auth_check ($bf_ary, $bf_key, $perm_ary, $perm_key, $is_admin = false)
{
	if ($is_admin) return true;
	if (!isset($perm_ary[$perm_key])) return false;

	return bf($perm_ary[$perm_key], $bf_ary, $bf_key);
}

class Date_Delta
{
	var $auto_granularity = array(
		60        => 'seconds',   // set granularity to "seconds" if delta less then 1 minute
		10800     => 'minutes',   // 3 hours
		259200    => 'hours',     // 3 days
		31363200  => 'mday',      // 12 months
		311040000 => 'mon',       // 10 years
	);
	var $intervals = array();
	var $format    = '';

	// Creates new object.
	function Date_Delta()
	{
		global $lang;

		$this->intervals = $lang['DELTA_TIME']['INTERVALS'];
		$this->format = $lang['DELTA_TIME']['FORMAT'];
	}

	// Makes the spellable phrase.
	function spellDelta($first, $last, $from = 'auto')
	{
		if ($last < $first)
		{
			$old_first = $first;
			$first = $last;
			$last = $old_first;
		}

		if ($from == 'auto')
		{
			$from = 'year';
			$diff = $last - $first;
			foreach ($this->auto_granularity as $seconds_count => $granule)
			{
				if ($diff < $seconds_count)
				{
					$from = $granule;
					break;
				}
			}
		}

		// Solve data delta.
		$delta = $this->getDelta($first, $last);
		if (!$delta) return false;

		// Make spellable phrase.
		$parts = array();
		$intervals = $GLOBALS['lang']['DELTA_TIME']['INTERVALS'];

		foreach (array_reverse($delta) as $k => $n)
		{
			if (!$n)
			{
				if ($k == $from)
				{
					if (!$parts)
					{
						$parts[] = declension($n, $this->intervals[$k], $this->format);
					}
					break;
				}
				continue;
			}
			$parts[] = declension($n, $this->intervals[$k], $this->format);
			if ($k == $from) break;
		}
		return join(' ', $parts);
	}

	// returns the associative array with date deltas.
	function getDelta($first, $last)
	{
		if ($last < $first) return false;

		// Solve H:M:S part.
		$hms = ($last - $first) % (3600 * 24);
		$delta['seconds'] = $hms % 60;
		$delta['minutes'] = floor($hms/60) % 60;
		$delta['hours']   = floor($hms/3600) % 60;

		// Now work only with date, delta time = 0.
		$last -= $hms;
		$f = getdate($first);
		$l = getdate($last); // the same daytime as $first!

		$dYear = $dMon = $dDay = 0;

		// Delta day. Is negative, month overlapping.
		$dDay += $l['mday'] - $f['mday'];
		if ($dDay < 0) {
			$monlen = $this->monthLength(date('Y', $first), date('m', $first));
			$dDay += $monlen;
			$dMon--;
		}
		$delta['mday'] = $dDay;

		// Delta month. If negative, year overlapping.
		$dMon += $l['mon'] - $f['mon'];
		if ($dMon < 0) {
			$dMon += 12;
			$dYear --;
		}
		$delta['mon'] = $dMon;

		// Delta year.
		$dYear += $l['year'] - $f['year'];
		$delta['year'] = $dYear;

		return $delta;
	}

	// Returns the length (in days) of the specified month.
	function monthLength($year, $mon)
	{
		$l = 28;
		while (checkdate($mon, $l+1, $year)) $l++;
		return $l;
	}
}

function delta_time ($timestamp_1, $timestamp_2 = TIMENOW, $granularity = 'auto')
{
	return $GLOBALS['DeltaTime']->spellDelta($timestamp_1, $timestamp_2, $granularity);
}

function get_select ($type)
{
	global $lang;

	$select_ary = array();

	switch ($type)
	{
		case 'groups':

			$sql = "SELECT group_id, group_name
				FROM ". BB_GROUPS ."
				WHERE group_single_user = 0
				ORDER BY group_name";

			foreach (DB()->fetch_rowset($sql) as $row)
			{
				if (isset($select_ary[$row['group_name']]))
				{
					$cnt = md5($row['group_name']) .'_cnt';
					$$cnt = @$$cnt + 1;
					$row['group_name'] = $row['group_name'] . ' ['. (int) $$cnt .']';
				}
				$select_ary[$row['group_name']] = $row['group_id'];
			}
			$select_name = POST_GROUPS_URL;
			break;

		case 'forum_tpl':
			$sql = "SELECT tpl_id, tpl_name FROM ". BB_TOPIC_TPL ." ORDER BY tpl_name";
			$select_ary[$lang['SELECT']] = 0;
			foreach (DB()->fetch_rowset($sql) as $row)
			{
				$select_ary[$row['tpl_name']] = $row['tpl_id'];
			}
			$select_name = 'forum_tpl_select';
			break;
	}
	return ($select_ary) ? build_select($select_name, $select_ary) : '';
}

class html_common
{
	var $options    = '';
	var $attr       = array();
	var $cur_attr   = null;
	var $max_length = HTML_SELECT_MAX_LENGTH;
	var $selected   = array();

	function build_select ($name, $params, $selected = null, $max_length = HTML_SELECT_MAX_LENGTH, $multiple_size = null, $js = '')
	{
		if (empty($params)) return '';

		$this->options = '';
		$this->selected = array_flip((array) $selected);
		$this->max_length = $max_length;

		$this->attr = array();
		$this->cur_attr =& $this->attr;

		if (isset($params['__attributes']))
		{
			$this->attr = $params['__attributes'];
			unset($params['__attributes']);
		}

		$this->_build_select_rec($params);

		$select_params  = ($js) ? " $js" : '';
		$select_params .= ($multiple_size) ? ' multiple="multiple" size="'. $multiple_size .'"' : '';
		$select_params .= ' name="'. htmlCHR($name) .'"';
		$select_params .= ' id="'. htmlCHR($name) .'"';

		return "\n<select $select_params>\n". $this->options ."</select>\n";
	}

	function _build_select_rec ($params)
	{
		foreach ($params as $opt_name => $opt_val)
		{
			$opt_name = rtrim($opt_name);

			if (is_array($opt_val))
			{
				$this->cur_attr =& $this->cur_attr[$opt_name];

				$label = htmlCHR(str_short($opt_name, $this->max_length));

				$this->options .= "\t<optgroup label=\"&nbsp;". $label ."\">\n";
				$this->_build_select_rec($opt_val);
				$this->options .= "\t</optgroup>\n";

				$this->cur_attr =& $this->attr;
			}
			else
			{
				$text  = htmlCHR(str_short($opt_name, $this->max_length));
				$value = ' value="'. htmlCHR($opt_val) .'"';

				$class = isset($this->cur_attr[$opt_name]['class']) ? ' class="'. $this->cur_attr[$opt_name]['class'] .'"' : '';
				$style = isset($this->cur_attr[$opt_name]['style']) ? ' style="'. $this->cur_attr[$opt_name]['style'] .'"' : '';

				$selected = isset($this->selected[$opt_val]) ? HTML_SELECTED : '';
				$disabled = isset($this->cur_attr[$opt_name]['disabled']) ? HTML_DISABLED : '';

				$this->options .= "\t\t<option". $class . $style . $selected . $disabled . $value .'>&nbsp;'. $text ."&nbsp;</option>\n";
			}
		}
	}

	function array2html ($array, $ul = 'ul', $li = 'li')
	{
		$this->out = '';
		$this->_array2html_rec($array, $ul, $li);
		return "<$ul class=\"tree-root\">{$this->out}</$ul>";
	}

	function _array2html_rec ($array, $ul, $li)
	{
		@natsort($array);
		foreach ($array as $k => $v)
		{
			if (is_array($v))
			{
				$this->out .= "<$li><span class=\"b\">$k</span><$ul>";
				$this->_array2html_rec($v, $ul, $li);
				$this->out .= "</$ul></$li>";
			}
			else
			{
				$this->out .= "<$li><span>$v</span></$li>";
			}
		}
	}

	// all arguments should be already htmlspecialchar()d (if needed)
	function build_checkbox ($name, $title, $checked = false, $disabled = false, $class = null, $id = null, $value = 1)
	{
		$name     = ' name="'. $name .'" ';
		$value    = ' value="'. $value .'" ';
		$title    = ($class) ? '<span class="'. $class .'">'. $title .'</span>' : $title;
		$id       = ($id) ? " id=\"$id\" " : '';
		$checked  = ($checked) ? HTML_CHECKED : '';
		$disabled = ($disabled) ? HTML_DISABLED : '';

		return '<label><input type="checkbox" '. $id . $name . $value . $checked . $disabled .' />&nbsp;'. $title .'&nbsp;</label>';
	}

#	function build_option ($opt_name, $opt_val, $selected = null, $max_length = false)
#	{
#		return "\t\t<option value=\"". htmlCHR($opt_val) .'"'. (($selected) ? ' selected="selected"' : '') .'>'. htmlCHR(str_short($opt_name, $max_length)) ."</option>\n";
#	}

#	function build_optgroup ($label, $contents, $max_length = false)
#	{
#		return "\t<optgroup label=\"&nbsp;". htmlCHR(str_short($label, $max_length)) ."\">\n". $contents ."\t</optgroup>\n";
#	}
}

function build_select ($name, $params, $selected = null, $max_length = HTML_SELECT_MAX_LENGTH, $multiple_size = null, $js = '')
{
	return $GLOBALS['html']->build_select($name, $params, $selected, $max_length, $multiple_size, $js);
}

function build_checkbox ($name, $title, $checked = false, $disabled = false, $class = null, $id = null, $value = 1)
{
	return $GLOBALS['html']->build_checkbox($name, $title, $checked, $disabled, $class, $id, $value);
}

function replace_quote ($str, $double = true, $single = true)
{
	if ($double) $str = str_replace('"', '&quot;', $str);
	if ($single) $str = str_replace("'", '&#039;', $str);
	return $str;
}

/**
* Build simple hidden fields from array
*/
function build_hidden_fields ($fields_ary)
{
	$out = "\n";

	foreach ($fields_ary as $name => $val)
	{
		if (is_array($val))
		{
			foreach ($val as $ary_key => $ary_val)
			{
				$out .= '<input type="hidden" name="'. $name .'['. $ary_key .']" value="'. $ary_val ."\" />\n";
			}
		}
		else
		{
			$out .= '<input type="hidden" name="'. $name .'" value="'. $val ."\" />\n";
		}
	}

	return $out;
}

/**
 * Choost russian word declension based on numeric [from dklab.ru]
 * Example for $expressions: array("ответ", "ответа", "ответов")
 */
function declension ($int, $expressions, $format = '%1$s %2$s')
{
	if (!is_array($expressions))
	{
		$expressions = $GLOBALS['lang']['DECLENSION'][strtoupper($expressions)];
	}

	if (count($expressions) < 3)
	{
		$expressions[2] = $expressions[1];
	}
	$count = intval($int) % 100;

	if ($count >= 5 && $count <= 20)
	{
		$result = $expressions['2'];
	}
	else
	{
		$count = $count % 10;
		if ($count == 1)
		{
			$result = $expressions['0'];
		}
		elseif ($count >= 2 && $count <= 4)
		{
			$result = $expressions['1'];
		}
		else
		{
			$result = $expressions['2'];
		}
	}

	return ($format) ? sprintf($format, $int, $result) : $result;
}

// http://forum.dklab.ru/php/advises/UrlreplaceargChangesValueOfParameterInUrl.html
function url_arg ($url, $arg, $value, $amp = '&amp;')
{
	$arg = preg_quote($arg, '/');

	// разделяем URL и ANCHOR
	$anchor = '';
	if (preg_match('/(.*)(#.*)/s', $url, $m))
	{
		$url    = $m[1];
		$anchor = $m[2];
	}
	// заменяем параметр, если он существует
	if (preg_match("/((\?|&|&amp;)$arg=)[^&]*/s", $url, $m))
	{
		$cur = $m[0];
		$new = is_null($value) ? '' : $m[1] . urlencode($value);
		$url = str_replace($cur, $new, $url);
	}
	// добавляем параметр
	else if (!is_null($value))
	{
		$div = (strpos($url, '?') !== false) ? $amp : '?';
		$url = $url . $div . $arg .'='. urlencode($value);
	}
	return $url . $anchor;
}

/**
 * Adds commas between every group of thousands
 */
function commify ($number)
{
	return number_format($number);
}

/**
 * Returns a size formatted in a more human-friendly format, rounded to the nearest GB, MB, KB..
 */
function humn_size ($size, $rounder = null, $min = null, $space = '&nbsp;')
{
	static $sizes   = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	static $rounders = array(0,   0,    0,    2,    3,    3,    3,    3,    3);

	$size = (float) $size;
	$ext = $sizes[0];
	$rnd = $rounders[0];

	if ($min == 'KB' && $size < 1024)
	{
		$size = $size / 1024;
		$ext  = 'KB';
		$rounder = 1;
	}
	else
	{
		for ($i=1, $cnt=count($sizes); ($i < $cnt && $size >= 1024); $i++)
		{
			$size = $size / 1024;
			$ext  = $sizes[$i];
			$rnd  = $rounders[$i];
		}
	}
	if (!$rounder)
	{
		$rounder = $rnd;
	}

	return round($size, $rounder) . $space . $ext;
}

function bt_show_ip ($ip, $port = '')
{
	global $bb_cfg;

	if (IS_AM)
	{
		$ip = decode_ip($ip);
		$ip .= ($port) ? ":$port" : '';
		return $ip;
	}
	else
	{
		return ($bb_cfg['bt_show_ip_only_moder']) ? false : decode_ip_xx($ip);
	}
}

function bt_show_port ($port)
{
	global $bb_cfg;

	if (IS_AM)
	{
		return $port;
	}
	else
	{
		return ($bb_cfg['bt_show_port_only_moder']) ? false : $port;
	}
}

function decode_ip_xx ($ip)
{
	$h = explode('.', chunk_split($ip, 2, '.'));
	return hexdec($h[0]) .'.'. hexdec($h[1]) .'.'. hexdec($h[2]) .'.xx';
}

function checkbox_get_val (&$key, &$val, $default = 1, $on = 1, $off = 0)
{
	global $previous_settings, $search_id;

	if (isset($_REQUEST[$key]))
	{
		$val = (int) $_REQUEST[$key];
	}
	else if (!isset($_REQUEST[$key]) && isset($_REQUEST['prev_'. $key]))
	{
		$val = $off;
	}
	else if (isset($previous_settings[$key]) && (!IS_GUEST || !empty($search_id)))
	{
		$val = ($previous_settings[$key]) ? $on : $off;
	}
	else
	{
		$val = $default;
	}
}

function select_get_val ($key, &$val, $options_ary, $default, $num = true)
{
	global $previous_settings;

	if (isset($_REQUEST[$key]))
	{
		if (isset($options_ary[$_REQUEST[$key]]))
		{
			$val = ($num) ? intval($_REQUEST[$key]) : $_REQUEST[$key];
		}
	}
	else if (isset($previous_settings[$key]))
	{
		$val = $previous_settings[$key];
	}
	else
	{
		$val = $default;
	}
}

/**
* set_var
*
* Set variable, used by {@link request_var the request_var function}
*
* @access private
*/
function set_var(&$result, $var, $type, $multibyte = false, $strip = true)
{
	settype($var, $type);
	$result = $var;

	if ($type == 'string')
	{
		$result = trim(htmlspecialchars(str_replace(array("\r\n", "\r"), array("\n", "\n"), $result)));

		if (!empty($result))
		{
			// Make sure multibyte characters are wellformed
			if ($multibyte)
			{
				if (!preg_match('/^./u', $result))
				{
					$result = '';
				}
			}
		}

		$result = ($strip) ? stripslashes($result) : $result;
	}
}
/**
* request_var
*
* Used to get passed variable
*/
function request_var($var_name, $default, $multibyte = false, $cookie = false)
{
	if (!$cookie && isset($_COOKIE[$var_name]))
	{
		if (!isset($_GET[$var_name]) && !isset($_POST[$var_name]))
		{
			return (is_array($default)) ? array() : $default;
		}
		$_REQUEST[$var_name] = isset($_POST[$var_name]) ? $_POST[$var_name] : $_GET[$var_name];
	}

	if (!isset($_REQUEST[$var_name]) || (is_array($_REQUEST[$var_name]) && !is_array($default)) || (is_array($default) && !is_array($_REQUEST[$var_name])))
	{
		return (is_array($default)) ? array() : $default;
	}

	$var = $_REQUEST[$var_name];
	if (!is_array($default))
	{
		$type = gettype($default);
	}
	else
	{
		list($key_type, $type) = each($default);
		$type = gettype($type);
		$key_type = gettype($key_type);
		if ($type == 'array')
		{
			reset($default);
			$default = current($default);
			list($sub_key_type, $sub_type) = each($default);
			$sub_type = gettype($sub_type);
			$sub_type = ($sub_type == 'array') ? 'NULL' : $sub_type;
			$sub_key_type = gettype($sub_key_type);
		}
	}

	if (is_array($var))
	{
		$_var = $var;
		$var = array();

		foreach ($_var as $k => $v)
		{
			set_var($k, $k, $key_type);
			if ($type == 'array' && is_array($v))
			{
				foreach ($v as $_k => $_v)
				{
					if (is_array($_v))
					{
						$_v = null;
					}
					set_var($_k, $_k, $sub_key_type);
					set_var($var[$k][$_k], $_v, $sub_type, $multibyte);
				}
			}
			else
			{
				if ($type == 'array' || is_array($v))
				{
					$v = null;
				}
				set_var($var[$k], $v, $type, $multibyte);
			}
		}
	}
	else
	{
		set_var($var, $var, $type, $multibyte);
	}

	return $var;
}

function get_username ($user_id)
{
	if (empty($user_id)) return false;
	$row = DB()->fetch_row("SELECT username FROM ". BB_USERS ." WHERE user_id = $user_id LIMIT 1");
	return $row['username'];
}

function get_user_id ($username)
{
	if (empty($username)) return false;
	$row = DB()->fetch_row("SELECT user_id FROM ". BB_USERS ." WHERE username = '$username' LIMIT 1");
	return $row['user_id'];
}

function str_short ($text, $max_length, $space = ' ')
{
	if ($max_length && mb_strlen($text, 'UTF-8') > $max_length)
	{
		$text = mb_substr($text, 0, $max_length, 'UTF-8');

		if ($last_space_pos = $max_length - intval(strpos(strrev($text), $space)))
		{
			if ($last_space_pos > round($max_length * 3/4))
			{
				$last_space_pos--;
				$text = mb_substr($text, 0, $last_space_pos, 'UTF-8');
			}
		}
		$text .= '...';
		$text = preg_replace('!&#?(\w+)?;?(\w{1,5})?\.\.\.$!', '...', $text);
	}
	return $text;
}

function wbr ($text, $max_word_length = HTML_WBR_LENGTH)
{
	return preg_replace("/([\w\->;:.,~!?(){}@#$%^*\/\\\\]{". $max_word_length ."})/ui", '$1<wbr>', $text);
}

function get_bt_userdata ($user_id)
{
	return DB()->fetch_row("SELECT bt.*, SUM(tr.speed_up) as speed_up, SUM(tr.speed_down) as speed_down
                            FROM      ". BB_BT_USERS   ." bt
                            LEFT JOIN ". BB_BT_TRACKER ." tr ON (bt.user_id = tr.user_id)
                            WHERE bt.user_id = ". (int) $user_id ."
                            GROUP BY bt.user_id");
}

function get_bt_ratio ($btu)
{
	return
		(!empty($btu['u_down_total']) && $btu['u_down_total'] > MIN_DL_FOR_RATIO)
		? round((($btu['u_up_total'] + $btu['u_up_release'] + $btu['u_up_bonus']) / $btu['u_down_total']), 2)
		: null
	;
}

function show_bt_userdata ($user_id)
{
	global $lang;

	$btu = get_bt_userdata($user_id);

	$GLOBALS['template']->assign_vars(array(
		'SHOW_BT_USERDATA' => true,
		'UP_TOTAL'         => humn_size($btu['u_up_total']),
		'UP_BONUS'         => humn_size($btu['u_up_bonus']),
		'RELEASED'         => humn_size($btu['u_up_release']),
		'DOWN_TOTAL'       => humn_size($btu['u_down_total']),
		'DOWN_TOTAL_BYTES' => $btu['u_down_total'],
		'USER_RATIO'       => get_bt_ratio($btu),
		'MIN_DL_FOR_RATIO' => humn_size(MIN_DL_FOR_RATIO),
		'MIN_DL_BYTES'     => MIN_DL_FOR_RATIO,
		'AUTH_KEY'         => ($btu['auth_key']) ? $btu['auth_key'] : $lang['NONE'],
		'SPEED_UP'         => ($btu['speed_up']) ? humn_size($btu['speed_up']).'/s' : '0 KB/s',
		'SPEED_DOWN'       => ($btu['speed_down']) ? humn_size($btu['speed_down']).'/s' : '0 KB/s',
	));
}

function get_attachments_dir ($cfg = null)
{
	if (!$cfg AND !$cfg = $GLOBALS['attach_config'])
	{
		$cfg = bb_get_config(BB_ATTACH_CONFIG, true, false);
	}

	if (!$cfg['allow_ftp_upload'])
	{
		if ($cfg['upload_dir'][0] == '/' || ($cfg['upload_dir'][0] != '/' && $cfg['upload_dir'][1] == ':'))
		{
			return $cfg['upload_dir'];
		}
		else
		{
			return BB_ROOT . $cfg['upload_dir'];
		}
	}
	else
	{
		return $cfg['download_path'];
	}
}

function bb_get_config ($table, $from_db = false, $update_cache = true)
{
	if ($from_db OR !$cfg = CACHE('bb_cache')->get("config_{$table}"))
	{
		$cfg = array();
		foreach (DB()->fetch_rowset("SELECT * FROM $table") as $row)
		{
			$cfg[$row['config_name']] = $row['config_value'];
		}
		if ($update_cache)
		{
			CACHE('bb_cache')->set("config_{$table}", $cfg);
		}
	}
	return $cfg;
}

function bb_update_config ($params, $table = BB_CONFIG)
{
	$updates = array();
	foreach ($params as $name => $val)
	{
		$updates[] = array(
			'config_name'  => $name,
			'config_value' => $val,
		);
	}
	$updates = DB()->build_array('MULTI_INSERT', $updates);

	DB()->query("REPLACE INTO $table $updates");
	// Update cache
	bb_get_config($table, true, true);
}

function get_db_stat($mode)
{
	switch( $mode )
	{
		case 'usercount':
			$sql = "SELECT COUNT(user_id) AS total
				FROM " . BB_USERS;
			break;

		case 'newestuser':
			$sql = "SELECT user_id, username
				FROM " . BB_USERS . "
				WHERE user_id <> " . ANONYMOUS . "
				ORDER BY user_id DESC
				LIMIT 1";
			break;

		case 'postcount':
		case 'topiccount':
			$sql = "SELECT SUM(forum_topics) AS topic_total, SUM(forum_posts) AS post_total
				FROM " . BB_FORUMS;
			break;
	}

	if ( !($result = DB()->sql_query($sql)) )
	{
		return false;
	}

	$row = DB()->sql_fetchrow($result);

	switch ( $mode )
	{
		case 'usercount':
			return $row['total'];
			break;
		case 'newestuser':
			return $row;
			break;
		case 'postcount':
			return $row['post_total'];
			break;
		case 'topiccount':
			return $row['topic_total'];
			break;
	}

	return false;
}

// added at phpBB 2.0.11 to properly format the username
function clean_username($username)
{
	$username = mb_substr(htmlspecialchars(str_replace("\'", "'", trim($username))), 0, 25, 'UTF-8');
	$username = phpbb_rtrim($username, "\\");
	$username = str_replace("'", "\'", $username);

	return $username;
}

/**
* This function is a wrapper for ltrim, as charlist is only supported in php >= 4.1.0
* Added in phpBB 2.0.18
*/
function phpbb_ltrim($str, $charlist = false)
{
	if ($charlist === false)
	{
		return ltrim($str);
	}

	$php_version = explode('.', PHP_VERSION);

	// php version < 4.1.0
	if ((int) $php_version[0] < 4 || ((int) $php_version[0] == 4 && (int) $php_version[1] < 1))
	{
		while ($str{0} == $charlist)
		{
			$str = substr($str, 1);
		}
	}
	else
	{
		$str = ltrim($str, $charlist);
	}

	return $str;
}

// added at phpBB 2.0.12 to fix a bug in PHP 4.3.10 (only supporting charlist in php >= 4.1.0)
function phpbb_rtrim($str, $charlist = false)
{
	if ($charlist === false)
	{
		return rtrim($str);
	}

	$php_version = explode('.', PHP_VERSION);

	// php version < 4.1.0
	if ((int) $php_version[0] < 4 || ((int) $php_version[0] == 4 && (int) $php_version[1] < 1))
	{
		while ($str{strlen($str)-1} == $charlist)
		{
			$str = substr($str, 0, strlen($str)-1);
		}
	}
	else
	{
		$str = rtrim($str, $charlist);
	}

	return $str;
}

//
// Get Userdata, $u can be username or user_id. If force_str is true, the username will be forced.
//
function get_userdata ($u, $force_name = false, $allow_anon = false)
{
	if (!$u) return false;

	if (intval($u) == ANONYMOUS && $allow_anon)
	{
		if ($userdata = CACHE('bb_cache')->get('anonymous_userdata'))
		{
			return $userdata;
		}
	}

	$userdata = array();
	$name_search = false;
	$anon_sql = (!$allow_anon) ? "AND user_id != ". ANONYMOUS : '';

	if ($force_name || !is_numeric($u))
	{
		$name_search = true;
		$where_sql = "WHERE username = '". clean_username($u) ."'";
	}
	else
	{
		$where_sql = "WHERE user_id = ". (int) $u;
	}

	$sql = "SELECT * FROM ". BB_USERS ." $where_sql $anon_sql LIMIT 1";

	if (!$userdata = DB()->fetch_row($sql))
	{
		if (!is_int($u) && !$name_search)
		{
			$where_sql = "WHERE username = '". clean_username($u) ."'";
			$sql = "SELECT * FROM ". BB_USERS ." $where_sql $anon_sql LIMIT 1";
			$userdata = DB()->fetch_row($sql);
		}
	}

	if ($userdata['user_id'] == ANONYMOUS)
	{
		CACHE('bb_cache')->set('anonymous_userdata', $userdata);
	}

	return $userdata;
}

function make_jumpbox ($selected = 0)
{
	global $datastore, $template;

	if (!$jumpbox = $datastore->get('jumpbox'))
	{
		$datastore->update('jumpbox');
		$jumpbox = $datastore->get('jumpbox');
	}

	$template->assign_vars(array(
		'JUMPBOX' => (IS_GUEST) ? $jumpbox['guest'] : $jumpbox['user'],
	));
}

// $mode: array(not_auth_forum1,not_auth_forum2,..) or (string) 'mode'
function get_forum_select ($mode = 'guest', $name = POST_FORUM_URL, $selected = null, $max_length = HTML_SELECT_MAX_LENGTH, $multiple_size = null, $js = '', $all_forums_option = null)
{
	global $lang, $datastore;

	if (is_array($mode))
	{
		$not_auth_forums_fary = array_flip($mode);
		$mode = 'not_auth_forums';
	}
	if (is_null($max_length))
	{
		$max_length = HTML_SELECT_MAX_LENGTH;
	}
	$select = is_null($all_forums_option) ? array() : array($lang['ALL_AVAILABLE'] => $all_forums_option);
	if (!$forums = $datastore->get('cat_forums'))
	{
		$datastore->update('cat_forums');
		$forums = $datastore->get('cat_forums');
	}

	foreach ($forums['f'] as $fid => $f)
	{
		switch ($mode)
		{
			case 'guest':
				if ($f['auth_view'] != AUTH_ALL) continue 2;
				break;

			case 'user':
				if ($f['auth_view'] != AUTH_ALL && $f['auth_view'] != AUTH_REG) continue 2;
				break;

			case 'not_auth_forums':
				if (isset($not_auth_forums_fary[$f['forum_id']])) continue 2;
				break;

			case 'admin':
				break;

			default:
				trigger_error(__FUNCTION__ .": invalid mode '$mode'", E_USER_ERROR);
		}
		$cat_title = $forums['c'][$f['cat_id']]['cat_title'];
		$f_name = ($f['forum_parent']) ? ' |- ' : '';
		$f_name .= $f['forum_name'];

		while (isset($select[$cat_title][$f_name]))
		{
			$f_name .= ' ';
		}

		$select[$cat_title][$f_name] = $fid;

		if (!$f['forum_parent'])
		{
			$class = 'root_forum';
			$class .= isset($f['subforums']) ? ' has_sf' : '';
			$select['__attributes'][$cat_title][$f_name]['class'] = $class;
		}
	}

	return build_select($name, $select, $selected, $max_length, $multiple_size, $js);
}

function setup_style ()
{
	global $bb_cfg, $template;

	// AdminCP works only with default template
	$tpl_dir_name = defined('IN_ADMIN') ? 'default'   : basename($bb_cfg['tpl_name']);
	$stylesheet   = defined('IN_ADMIN') ? 'main.css'  : basename($bb_cfg['stylesheet']);
	$theme_css    = defined('IN_ADMIN') ? 'admin.css' : basename($bb_cfg['theme_css']);

	$template = new Template(TEMPLATES_DIR . $tpl_dir_name);
	$css_dir = BB_ROOT . basename(TEMPLATES_DIR) ."/$tpl_dir_name/css/";

	$template->assign_vars(array(
		'BB_ROOT'          => BB_ROOT,
		'SPACER'           => BB_ROOT .'images/spacer.gif',
		'STYLESHEET'       => $css_dir . $stylesheet,
		'THEME_CSS'        => ($theme_css) ? $css_dir . $theme_css : '',
		'EXT_LINK_NEW_WIN' => $bb_cfg['ext_link_new_win'],
	));

	require(TEMPLATES_DIR . $tpl_dir_name .'/tpl_config.php');

	$theme = array('template_name' => $tpl_dir_name);

	return $theme;
}

// Create date/time from format and timezone
function bb_date ($gmepoch, $format = false, $tz = null)
{
    global $bb_cfg, $lang, $userdata;

    if (!$format) $format = $bb_cfg['default_dateformat'];
    if (empty($lang))require_once($bb_cfg['default_lang_dir'] .'lang_main.php');

    if (is_null($tz) || $tz == 'false')
    {
        if (empty($userdata['session_logged_in']))
        {
            $tz2 = $bb_cfg['board_timezone'];
        }
        else $tz2 = $userdata['user_timezone'];
    }
    elseif (is_numeric($tz)) $tz2 = $tz;

    $date = gmdate($format, $gmepoch + (3600 * $tz2));

    if($tz != 'false')
    {
        $time_format = " H:i";

        $today = gmdate("d", TIMENOW + (3600 * $tz2));
        $month = gmdate("m", TIMENOW + (3600 * $tz2));
        $year  = gmdate("Y", TIMENOW + (3600 * $tz2));

        $date_today = gmdate("d", $gmepoch + (3600 * $tz2));
        $date_month = gmdate("m", $gmepoch + (3600 * $tz2));
        $date_year  = gmdate("Y", $gmepoch + (3600 * $tz2));

        if ($date_today == $today && $date_month == $month && $date_year == $year)
        {
            $date = 'today' . gmdate($time_format, $gmepoch + (3600 * $tz2));
        }
        elseif ($today != 1 && $date_today == ($today-1) && $date_month == $month && $date_year == $year)
        {
            $date = 'yesterday' . gmdate($time_format, $gmepoch + (3600 * $tz2));
        }
        elseif ($today == 1 && $month != 1)
        {
            $yesterday = date ("t", mktime(0, 0, 0, ($month-1), 1, $year));
            if ($date_today == $yesterday && $date_month == ($month-1) && $date_year == $year)
                $date = 'yesterday' . gmdate($time_format, $gmepoch + (3600 * $tz2));
        }
        elseif ($today == 1 && $month == 1)
        {
            $yesterday = date ("t", mktime(0, 0, 0, 12, 1, ($year -1)));
            if ($date_today == $yesterday && $date_month == 12 && $date_year == ($year-1))
                $date = 'yesterday' . gmdate($time_format, $gmepoch + (3600 * $tz));
        }
    }
    return ($bb_cfg['translate_dates']) ? strtr(strtoupper($date), $lang['DATETIME']) : $date;
}

// Birthday
// Add function mkrealdate for Birthday MOD
// the originate php "mktime()", does not work proberly on all OS, especially when going back in time
// before year 1970 (year 0), this function "mkrealtime()", has a mutch larger valid date range,
// from 1901 - 2099. it returns a "like" UNIX timestamp divided by 86400, so
// calculation from the originate php date and mktime is easy.
// mkrealdate, returns the number of day (with sign) from 1.1.1970.

function mkrealdate($day, $month, $birth_year)
{
	// define epoch
	$epoch = 0;
	// range check months
	if ($month < 1 || $month > 12) return "error";
	// range check days
	switch ($month)
	{
		case 1: if ($day > 31) return "error"; break;
		case 2: if ($day > 29) return "error";
			$epoch = $epoch+31; break;
		case 3: if ($day > 31) return "error";
			$epoch = $epoch+59; break;
		case 4: if ($day > 30) return "error" ;
			$epoch = $epoch+90; break;
		case 5: if ($day > 31) return "error";
			$epoch = $epoch+120; break;
		case 6: if ($day > 30) return "error";
			$epoch = $epoch+151; break;
		case 7: if ($day > 31) return "error";
			$epoch = $epoch+181; break;
		case 8: if ($day > 31) return "error";
			$epoch = $epoch+212; break;
		case 9: if ($day > 30) return "error";
			$epoch = $epoch+243; break;
		case 10: if ($day > 31) return "error";
			$epoch = $epoch+273; break;
		case 11: if ($day > 30) return "error";
			$epoch = $epoch+304; break;
		case 12: if ($day > 31) return "error";
			$epoch = $epoch+334; break;
	}
	$epoch = $epoch+$day;
	$epoch_Y = sqrt(($birth_year-1970)*($birth_year-1970));
	$leapyear = round((($epoch_Y+2) / 4)-.5);
	if (($epoch_Y+2)%4 == 0)
	{// curent year is leapyear
		$leapyear--;
		if ($birth_year > 1970 && $month >= 3) $epoch = $epoch+1;
		if ($birth_year < 1970 && $month < 3) $epoch = $epoch-1;
	}
	else if ($month == 2 && $day > 28) return "error";//only 28 days in feb.
	//year
	if ($birth_year > 1970)
	{
		$epoch = $epoch + $epoch_Y*365-1 + $leapyear;
	}
	else
	{
		$epoch = $epoch - $epoch_Y*365-1 - $leapyear;
	}
	return $epoch;
}

// Add function realdate for Birthday MOD
// the originate php "date()", does not work proberly on all OS, especially when going back in time
// before year 1970 (year 0), this function "realdate()", has a mutch larger valid date range,
// from 1901 - 2099. it returns a "like" UNIX date format (only date, related letters may be used, due to the fact that
// the given date value should already be divided by 86400 - leaving no time information left)
// a input like a UNIX timestamp divided by 86400 is expected, so
// calculation from the originate php date and mktime is easy.
// e.g. realdate ("m d Y", 3) returns the string "1 3 1970"

// UNIX users should replace this function with the below code, since this should be faster
//

function realdate($date, $format = "Ymd")
{
	if(!$date) return;
	return bb_date($date*86400+1, $format, 0);
}

function birthday_age($date, $list = 0)
{
	if(!$date) return;
	return delta_time(mktime(11, 0, 0, realdate($date, 'm'), realdate($date, 'd'), (realdate($date, 'Y') - $list)));
}

//
// Pagination routine, generates
// page number sequence
//
function generate_pagination($base_url, $num_items, $per_page, $start_item, $add_prevnext_text = TRUE)
{
	global $lang, $template;

// Pagination Mod
	$begin_end = 3;
	$from_middle = 1;
/*
	By default, $begin_end is 3, and $from_middle is 1, so on page 6 in a 12 page view, it will look like this:

	a, d = $begin_end = 3
	b, c = $from_middle = 1

 "begin"        "middle"           "end"
    |              |                 |
    |     a     b  |  c     d        |
    |     |     |  |  |     |        |
    v     v     v  v  v     v        v
    1, 2, 3 ... 5, 6, 7 ... 10, 11, 12

	Change $begin_end and $from_middle to suit your needs appropriately
*/

	$total_pages = ceil($num_items/$per_page);

	if ( $total_pages == 1 || $num_items == 0 )
	{
		return '';
	}

	$on_page = floor($start_item / $per_page) + 1;

	$page_string = '';
	if ( $total_pages > ((2*($begin_end + $from_middle)) + 2) )
	{
		$init_page_max = ( $total_pages > $begin_end ) ? $begin_end : $total_pages;
		for($i = 1; $i < $init_page_max + 1; $i++)
		{
			$page_string .= ( $i == $on_page ) ? '<b>' . $i . '</b>' : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
			if ( $i <  $init_page_max )
			{
				$page_string .= ", ";
			}
		}
		if ( $total_pages > $begin_end )
		{
			if ( $on_page > 1  && $on_page < $total_pages )
			{
				$page_string .= ( $on_page > ($begin_end + $from_middle + 1) ) ? ' ... ' : ', ';

				$init_page_min = ( $on_page > ($begin_end + $from_middle) ) ? $on_page : ($begin_end + $from_middle + 1);

				$init_page_max = ( $on_page < $total_pages - ($begin_end + $from_middle) ) ? $on_page : $total_pages - ($begin_end + $from_middle);

				for($i = $init_page_min - $from_middle; $i < $init_page_max + ($from_middle + 1); $i++)
				{
					$page_string .= ($i == $on_page) ? '<b>' . $i . '</b>' : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
					if ( $i <  $init_page_max + $from_middle )
					{
						$page_string .= ', ';
					}
				}
				$page_string .= ( $on_page < $total_pages - ($begin_end + $from_middle) ) ? ' ... ' : ', ';
			}
			else
			{
				$page_string .= '&nbsp;...&nbsp;';
			}
			for($i = $total_pages - ($begin_end - 1); $i < $total_pages + 1; $i++)
			{
				$page_string .= ( $i == $on_page ) ? '<b>' . $i . '</b>'  : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
				if( $i <  $total_pages )
				{
					$page_string .= ", ";
				}
			}
		}
	}
	else
	{
		for($i = 1; $i < $total_pages + 1; $i++)
		{
			$page_string .= ( $i == $on_page ) ? '<b>' . $i . '</b>' : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
			if ( $i <  $total_pages )
			{
				$page_string .= ', ';
			}
		}
	}

	if ( $add_prevnext_text )
	{
		if ( $on_page > 1 )
		{
			$page_string = ' <a href="' . append_sid($base_url . "&amp;start=" . ( ( $on_page - 2 ) * $per_page ) ) . '">' . $lang['PREVIOUS'] . '</a>&nbsp;&nbsp;' . $page_string;
		}

		if ( $on_page < $total_pages )
		{
			$page_string .= '&nbsp;&nbsp;<a href="' . append_sid($base_url . "&amp;start=" . ( $on_page * $per_page ) ) . '">' . $lang['NEXT'] . '</a>';
		}

	}

	$pagination = ($page_string) ? '<a class="menu-root" href="#pg-jump">'. $lang['GOTO_PAGE'] .'</a> :&nbsp;&nbsp;'. $page_string : '';
	$pagination = str_replace('&amp;start=0', '', $pagination);

	$template->assign_vars(array(
		'PAGINATION'   => $pagination,
		'PAGE_NUMBER'  => sprintf($lang['PAGE_OF'], ( floor($start_item/$per_page) + 1 ), ceil( $num_items / $per_page )),
		'PG_BASE_URL'  => $base_url,
		'PG_PER_PAGE'  => $per_page,
	));

	return $pagination;
}

//
// This does exactly what preg_quote() does in PHP 4-ish
// If you just need the 1-parameter preg_quote call, then don't bother using this.
//
function phpbb_preg_quote($str, $delimiter)
{
	$text = preg_quote($str);
	$text = str_replace($delimiter, '\\' . $delimiter, $text);

	return $text;
}

//
// Obtain list of naughty words and build preg style replacement arrays for use by the
// calling script, note that the vars are passed as references this just makes it easier
// to return both sets of arrays
//
function obtain_word_list(&$orig_word, &$replacement_word)
{
	global $bb_cfg;

	if (!$bb_cfg['use_word_censor']) return;

	if (!$sql = CACHE('bb_cache')->get('censored'))
	{
		$sql = DB()->fetch_rowset("SELECT word, replacement FROM ". BB_WORDS);
		CACHE('bb_cache')->set('censored', $sql, 7200);
	}

	foreach($sql as $row)
	{
		//$orig_word[] = '#(?<!\S)(' . str_replace('\*', '\S*?', preg_quote($row['word'], '#')) . ')(?!\S)#iu';
		$orig_word[] = '#(?<![\p{Nd}\p{L}_])(' . str_replace('\*', '[\p{Nd}\p{L}_]*?', preg_quote($row['word'], '#')) . ')(?![\p{Nd}\p{L}_])#iu';
		$replacement_word[] = $row['replacement'];
	}

	return true;
}

function smiley_sort ($a, $b)
{
	if (strlen($a['code']) == strlen($b['code']))
	{
		return 0;
	}

	return (strlen($a['code']) > strlen($b['code'])) ? -1 : 1;
}

function bb_die ($msg_text)
{
	if (defined('IN_AJAX'))
	{
		$GLOBALS['ajax']->ajax_die($msg_text);
	}
	message_die(GENERAL_MESSAGE, $msg_text);
}

function message_die ($msg_code, $msg_text = '', $msg_title = '', $err_line = '', $err_file = '', $sql = '')
{
	global $DBS, $template, $bb_cfg, $theme, $lang, $nav_links, $gen_simple_header, $images, $userdata;

	if (defined('HAS_DIED'))
	{
		trigger_error(__FUNCTION__ .' was called multiple times', E_USER_ERROR);
	}
	define('HAS_DIED', 1);
	define('DISABLE_CACHING_OUTPUT', true);
	$sql_store = $sql;
	$debug_text = '';

	// Get SQL error if we are debugging. Do this as soon as possible to prevent
	// subsequent queries from overwriting the status of sql_error()
	if (DEBUG && ($msg_code == GENERAL_ERROR || $msg_code == CRITICAL_ERROR))
	{
		if (!empty($DBS) && $sql_store)
		{
			$sql_error = $DBS->sql_error();
			$debug_text .= "<br /><br />SQL Error : {$sql_error['code']}<br /><br />{$sql_error['message']}";
		}
		if ($sql_store)
		{
			$debug_text .= "<br /><br />$sql_store";
		}
		if ($sql_store && $err_line && $err_file)
		{
			$debug_text .= "</br /><br />Line : {$err_line}<br />File : ". basename($err_file);
		}
	}

	if (empty($lang))
	{
		require($bb_cfg['default_lang_dir'] .'lang_main.php');
	}
	if (empty($userdata) && ($msg_code == GENERAL_MESSAGE || $msg_code == GENERAL_ERROR))
	{
		$userdata = session_pagestart();
	}
	// If the header hasn't been output then do it
	if (!defined('PAGE_HEADER_SENT') && $msg_code != CRITICAL_ERROR)
	{
		if (empty($template))
		{
			$template = new Template(BB_ROOT ."templates/{$bb_cfg['tpl_name']}");
		}
		if (empty($theme))
		{
			$theme = setup_style();
		}
		require(PAGE_HEADER);
	}

	switch ($msg_code)
	{
		case GENERAL_MESSAGE:
			if (!$msg_title) $msg_title = $lang['INFORMATION'];
			break;

		case CRITICAL_MESSAGE:
			if (!$msg_title) $msg_title = $lang['CRITICAL_INFORMATION'];
			break;

		case GENERAL_ERROR:
			if (!$msg_text)  $msg_text = $lang['AN_ERROR_OCCURED'];
			if (!$msg_title) $msg_title = $lang['GENERAL_ERROR'];
			break;

		case CRITICAL_ERROR:
			// Critical errors mean we cannot rely on _ANY_ DB information being
			// available so we're going to dump out a simple echo'd statement
			if (!$msg_text)  $msg_text = $lang['A_CRITICAL_ERROR'];
			if (!$msg_title) $msg_title = 'phpBB : <b>Critical Error</b>';
			break;
	}
	// Add on DEBUG info if we've enabled debug mode and this is an error. This
	// prevents debug info being output for general messages should DEBUG be
	// set TRUE by accident (preventing confusion for the end user!)
	if (DEBUG && ($msg_code == GENERAL_ERROR || $msg_code == CRITICAL_ERROR))
	{
		if ($debug_text)
		{
			$msg_text .= '<br /><br /><b><u>DEBUG MODE</u></b>'. $debug_text;
		}
	}

	if ($msg_code != CRITICAL_ERROR)
	{
		if (!empty($lang[$msg_text]))
		{
			$msg_text = $lang[$msg_text];
		}

		$template->assign_vars(array(
			'TPL_GENERAL_MESSAGE' => true,

			'MESSAGE_TITLE' => $msg_title,
			'MESSAGE_TEXT'  => $msg_text,
		));

		$template->set_filenames(array('message_die' => 'common.tpl'));
		$template->pparse('message_die');

		require(PAGE_FOOTER);
	}
	else
	{
		echo "<html>\n<body>\n". $msg_title ."\n<br /><br />\n". $msg_text ."</body>\n</html>";
	}
	exit;
}

function phpbb_realpath($path)
{
	return (!@function_exists('realpath') || !@realpath(INC_DIR . 'functions.php')) ? $path : @realpath($path);
}

function login_redirect ($url = '')
{
	redirect('login.php?redirect='. (($url) ? $url : $_SERVER['REQUEST_URI']));
}

function meta_refresh($url, $time = 5)
{
	global $template;

	$template->assign_var(
		'META' , '<meta http-equiv="refresh" content="' . $time . ';url=' . $url . '" />'
	);
}

function redirect ($url)
{
	global $bb_cfg;

	if (headers_sent($filename, $linenum))
	{
		trigger_error("Headers already sent in $filename($linenum)", E_USER_ERROR);
	}

	if (strstr(urldecode($url), "\n") || strstr(urldecode($url), "\r") || strstr(urldecode($url), ';url'))
	{
		message_die(CRITICAL_ERROR, 'Tried to redirect to potentially insecure url.');
	}

	if (!empty($_COOKIE['explain']))
	{
		message_die(GENERAL_MESSAGE, "redirect($url)");
	}

	$url = trim($url);
	$server_protocol = ($bb_cfg['cookie_secure']) ? 'https://' : 'http://';

	$server_name = preg_replace('#^\/?(.*?)\/?$#', '\1', trim($bb_cfg['server_name']));
	$server_port = ($bb_cfg['server_port'] <> 80) ? ':' . trim($bb_cfg['server_port']) : '';
	$script_name = preg_replace('#^\/?(.*?)\/?$#', '\1', trim($bb_cfg['script_path']));

	if ($script_name)
	{
		$script_name = "/$script_name";
		$url = preg_replace("#^$script_name#", '', $url);
	}

	$redirect_url = $server_protocol . $server_name . $server_port . $script_name . preg_replace('#^\/?(.*?)\/?$#', '/\1', $url);

	// Redirect via an HTML form for PITA webservers
	if (@preg_match('/Microsoft|WebSTAR|Xitami/', getenv('SERVER_SOFTWARE')))
	{
		header('Refresh: 0; URL='. $redirect_url);
		echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta http-equiv="refresh" content="0; url='. $redirect_url .'"><title>Redirect</title></head><body><div align="center">If your browser does not support meta redirection please click <a href="'. $redirect_url .'">HERE</a> to be redirected</div></body></html>';
		exit;
	}

	// Behave as per HTTP/1.1 spec for others
	header('Location: '. $redirect_url);
	exit;
}

//-- mod : topic display order ---------------------------------------------------------------------
//-- add
// build a list of the sortable fields or return field name
function get_forum_display_sort_option($selected_row=0, $action='list', $list='sort')
{
	global $lang;

	$forum_display_sort = array(
		'lang_key'	=> array('LASTPOST', 'SORT_TOPIC_TITLE', 'SORT_TIME'),
		'fields'	=> array('t.topic_last_post_time', 't.topic_title', 't.topic_time'),
	);
	$forum_display_order = array(
		'lang_key'	=> array('DESC', 'ASC'),
		'fields'	=> array('DESC', 'ASC'),
	);

	// get the good list
	$list_name = 'forum_display_' . $list;
	$listrow = $$list_name;

	// init the result
	$res = '';
	if ( $selected_row > count($listrow['lang_key']) )
	{
		$selected_row = 0;
	}

	// build list
	if ($action == 'list')
	{
		for ($i=0; $i < count($listrow['lang_key']); $i++)
		{
			$selected = ($i==$selected_row) ? ' selected="selected"' : '';
			$l_value = (isset($lang[$listrow['lang_key'][$i]])) ? $lang[$listrow['lang_key'][$i]] : $listrow['lang_key'][$i];
			$res .= '<option value="' . $i . '"' . $selected . '>' . $l_value . '</option>';
		}
	}
	else
	{
		// field
		$res = $listrow['fields'][$selected_row];
	}
	return $res;
}
//-- fin mod : topic display order -----------------------------------------------------------------

function topic_attachment_image($switch_attachment)
{
	global $is_auth;

	if (!$switch_attachment || !($is_auth['auth_download'] && $is_auth['auth_view']))
	{
		return '';
	}
	return '<img src="images/icon_clip.gif" alt="" border="0" /> ';
}

function transliterate ($str)
{
	static $translit_table;

	if (!isset($translit_table))
	{
		require(DEFAULT_LANG_DIR .'translit_table.php');
	}
	return strtr($str, $translit_table);
}

/**
 * array_combine()
 *
 * @package  PHP_Compat
 * @link     http://php.net/function.array_combine
 * @author   Aidan Lister <aidan@php.net>
 * @version  $Revision: 1.21 $
 * @since    PHP 5
 */
if (!function_exists('array_combine'))
{
	function array_combine($keys, $values)
	{
		if (!is_array($keys)) {
			user_error('array_combine() expects parameter 1 to be array, ' .
				gettype($keys) . ' given', E_USER_WARNING);
			return;
		}

		if (!is_array($values)) {
			user_error('array_combine() expects parameter 2 to be array, ' .
				gettype($values) . ' given', E_USER_WARNING);
			return;
		}

		$key_count   = count($keys);
		$value_count = count($values);
		if ($key_count !== $value_count) {
			user_error('array_combine() Both parameters should have equal number of elements', E_USER_WARNING);
			return false;
		}

		if ($key_count === 0 || $value_count === 0) {
			user_error('array_combine() Both parameters should have number of elements at least 0', E_USER_WARNING);
			return false;
		}

		$keys   = array_values($keys);
		$values = array_values($values);

		$combined = array();
		for ($i = 0; $i < $key_count; $i++) {
			$combined[$keys[$i]] = $values[$i];
		}

		return $combined;
	}
}

/**
 * array_intersect_key()
 *
 * @package     PHP_Compat
 * @link        http://php.net/function.array_intersect_key
 * @author      Tom Buskens <ortega@php.net>
 * @version     $Revision: 1.4 $
 * @since       PHP 5.0.2
 */
if (!function_exists('array_intersect_key')) {
    function array_intersect_key()
    {
        $args = func_get_args();
        if (count($args) < 2) {
            user_error('Wrong parameter count for array_intersect_key()', E_USER_WARNING);
            return;
        }

        // Check arrays
        $array_count = count($args);
        for ($i = 0; $i !== $array_count; $i++) {
            if (!is_array($args[$i])) {
                user_error('array_intersect_key() Argument #' .
                    ($i + 1) . ' is not an array', E_USER_WARNING);
                return;
            }
        }

        // Compare entries
        $result = array();
        foreach ($args[0] as $key1 => $value1) {
            for ($i = 1; $i !== $array_count; $i++) {
                foreach ($args[$i] as $key2 => $value2) {
                    if ((string) $key1 === (string) $key2) {
                        $result[$key1] = $value1;
                    }
                }
            }
        }

        return $result;
    }
}

function clear_dl_list ($topics_csv)
{
	DB()->query("DELETE FROM ". BB_BT_DLSTATUS ." WHERE topic_id IN($topics_csv)");
	DB()->query("DELETE FROM ". BB_BT_DLSTATUS_SNAP ." WHERE topic_id IN($topics_csv)");
}

// $ids - array(id1,id2,..) or (string) id
function get_id_csv ($ids)
{
	$ids = array_values((array) $ids);
	array_deep($ids, 'intval', 'one-dimensional');
	return (string) join(',', $ids);
}

// $ids - array(id1,id2,..) or (string) id1,id2,..
function get_id_ary ($ids)
{
	$ids = is_string($ids) ? explode(',', $ids) : array_values((array) $ids);
	array_deep($ids, 'intval', 'one-dimensional');
	return (array) $ids;
}

function get_topic_title ($topic_id)
{
	$row = DB()->fetch_row("
		SELECT topic_title FROM ". BB_TOPICS ." WHERE topic_id = ". (int) $topic_id ."
	");
	return $row['topic_title'];
}

function forum_exists ($forum_id)
{
	return DB()->fetch_row("SELECT forum_id FROM ". BB_FORUMS ." WHERE forum_id = $forum_id LIMIT 1");
}

function cat_exists ($cat_id)
{
	return DB()->fetch_row("SELECT cat_id FROM ". BB_CATEGORIES ." WHERE cat_id = $cat_id LIMIT 1");
}

//
// Action Log
//
class log_action
{
	var $log_type = array(
	#    LOG_TYPE_NAME   LOG_TYPE_ID
		'mod_topic_delete'   => 1,
		'mod_topic_move'     => 2,
		'mod_topic_lock'     => 3,
		'mod_topic_unlock'   => 4,
		'mod_post_delete'    => 5,
		'mod_topic_split'    => 6,
		'adm_user_delete'    => 7,
		'adm_user_ban'       => 8,
		'adm_user_unban'     => 9,
	);
	var $log_type_select = array();
	var $log_disabled = false;

	function init ()
	{
		global $lang, $bb_cfg;

		if (empty($lang['LOG_ACTION']))
		{
			require($bb_cfg['default_lang_dir'] .'lang_log_action.php');
		}

		foreach ($lang['LOG_ACTION']['LOG_TYPE'] as $log_type => $log_desc)
		{
			$this->log_type_select[strip_tags($log_desc)] = $this->log_type[$log_type];
		}
	}

	function mod ($type_name, $args = array())
	{
		global $userdata;

		if (empty($this->log_type)) $this->init();
		if ($this->log_disabled) return;

		$forum_id        =& $args['forum_id'];
		$forum_id_new    =& $args['forum_id_new'];
		$topic_id        =& $args['topic_id'];
		$topic_id_new    =& $args['topic_id_new'];
		$topic_title     =& $args['topic_title'];
		$topic_title_new =& $args['topic_title_new'];
		$log_msg         =& $args['log_msg'];

		if (!empty($userdata))
		{
			$user_id    = $userdata['user_id'];
			$username   = $userdata['username'];
			$session_ip = $userdata['session_ip'];
		}
		else
		{
			$user_id    = '';
			$username   = defined('IN_CRON') ? 'cron' : CLIENT_IP;
			$session_ip = '';
		}

		$sql_ary = array(
			'log_type_id'         => (int)    $this->log_type["$type_name"],
			'log_user_id'         => (int)    $user_id,
			'log_username'        => (string) $username,
			'log_user_ip'         => (string) $session_ip,
			'log_forum_id'        => (int)    $forum_id,
			'log_forum_id_new'    => (int)    $forum_id_new,
			'log_topic_id'        => (int)    $topic_id,
			'log_topic_id_new'    => (int)    $topic_id_new,
			'log_topic_title'     => (string) $topic_title,
			'log_topic_title_new' => (string) $topic_title_new,
			'log_time'            => (int)    TIMENOW,
			'log_msg'             => (string) $log_msg,
		);
		$sql_args = DB()->build_array('INSERT', $sql_ary);

		DB()->query("INSERT INTO ". BB_LOG ." $sql_args");
	}

	function admin ($type_name, $args = array())
	{
		$this->mod($type_name, $args);
	}
}

function get_topic_icon ($topic, $is_unread = null)
{
	global $bb_cfg, $lang, $images;

	$t_hot = ($topic['topic_replies'] >= $bb_cfg['hot_threshold']);
	$is_unread = is_null($is_unread) ? is_unread($topic['topic_last_post_time'], $topic['topic_id'], $topic['forum_id']) : $is_unread;

	if ($topic['topic_status'] == TOPIC_MOVED)
	{
		$folder_image = $images['folder'];
	}
	else
	{
		$folder = ($t_hot) ? $images['folder_hot'] : $images['folder'];
		$folder_new = ($t_hot) ? $images['folder_hot_new'] : $images['folder_new'];

		if ($topic['topic_type'] == POST_ANNOUNCE)
		{
			$folder = $images['folder_announce'];
			$folder_new = $images['folder_announce_new'];
		}
		else if ($topic['topic_type'] == POST_STICKY)
		{
			$folder = $images['folder_sticky'];
			$folder_new = $images['folder_sticky_new'];
		}
		else if ($topic['topic_status'] == TOPIC_LOCKED)
		{
			$folder = $images['folder_locked'];
			$folder_new = $images['folder_locked_new'];
		}
		else if ($topic['topic_dl_type'] == TOPIC_DL_TYPE_DL)
		{
			$folder = ($t_hot) ? $images['folder_dl_hot'] : $images['folder_dl'];
			$folder_new = ($t_hot) ? $images['folder_dl_hot_new'] : $images['folder_dl_new'];
		}

		$folder_image = ($is_unread) ? $folder_new : $folder;
	}

	return $folder_image;
}

function build_topic_pagination ($url, $replies, $per_page)
{
	$pg = '';

	if (++$replies > $per_page)
	{
		$total_pages = ceil($replies / $per_page);

		for ($j=0, $page=1; $j < $replies; $j += $per_page, $page++)
		{
			$href = ($j) ? "$url&amp;start=$j" : $url;
			$pg .= '<a href="'. $href .'" class="topicPG">'. $page .'</a>';

			if ($page == 1 && $total_pages > 3)
			{
				$pg .= ' .. ';
				$page = $total_pages - 2;
				$j += ($total_pages - 3) * $per_page;
			}
			else if ($page < $total_pages)
			{
				$pg .= ', ';
			}
		}
	}

	return $pg;
}

function print_confirmation ($tpl_vars)
{
	global $template, $lang;

	$template->assign_vars(array(
		'TPL_CONFIRM'   => true,
		'CONFIRM_TITLE' => $lang['CONFIRM'],
		'FORM_METHOD'   => 'post',
	));
	$template->assign_vars($tpl_vars);

	print_page('common.tpl');
}

/**
 *  $args = array(
 *            'tpl'    => 'template file name',
 *            'simple' => $gen_simple_header,
 *          );
 *       OR (string) 'template_file_name'
 *
 *  $type = ''        (common forum page)
 *          'admin'   (adminCP page)
 *          'simple'  (simple page without common header)
 *
 *  $mode = 'no_header'
 *          'no_footer'
 */
function print_page ($args, $type = '', $mode = '')
{
	global $template, $gen_simple_header;

	$tpl = (is_array($args) && !empty($args['tpl'])) ? $args['tpl'] : $args;
	$tpl = ($type === 'admin') ? ADMIN_TPL_DIR . $tpl : $tpl;

	$gen_simple_header = (is_array($args) && !empty($args['simple']) OR $type === 'simple') ? true : $gen_simple_header;

	if ($mode !== 'no_header')
	{
		require(PAGE_HEADER);
	}

	$template->set_filenames(array('body' => $tpl));
	$template->pparse('body');

	if ($mode !== 'no_footer')
	{
		require(PAGE_FOOTER);
	}
}

function caching_output ($enabled, $mode, $cache_var_name, $ttl = 300)
{
	if (!$enabled || !CACHE('bb_cache')->used)
	{
		return;
	}

	if ($mode == 'send')
	{
		if ($cached_contents = CACHE('bb_cache')->get($cache_var_name))
		{
			bb_exit($cached_contents);
		}
	}
	else if ($mode == 'store')
	{
		if ($output = ob_get_contents())
		{
			CACHE('bb_cache')->set($cache_var_name, $output, $ttl);
		}
	}
}

//
// Ajax
//
/**
 *  Encode PHP var to JSON (PHP -> JS)
 */
function bb_json_encode ($data)
{
	return json_encode($data);
}

/**
 *  Decode JSON to PHP (JS -> PHP)
 */
function bb_json_decode ($data)
{
	if (!is_string($data)) trigger_error('invalid argument for '. __FUNCTION__, E_USER_ERROR);
	return json_decode($data, true);
}

function clean_title ($str, $replace_underscore = false)
{
	$str = ($replace_underscore) ? str_replace('_', ' ', $str) : $str;
	$str = htmlCHR(str_compact($str));
	return $str;
}

function clean_text_match ($text, $ltrim_star = true, $remove_stopwords = false, $die_if_empty = false)
{
	global $bb_cfg, $lang;

	$text = str_compact($text);
	$ltrim_chars = ($ltrim_star) ? ' *-!' : ' ';
	$wrap_with_quotes = preg_match('#^"[^"]+"$#', $text);

#	$min_word_len = max(2, $bb_cfg['search_min_word_len'] - 1);
#	$max_word_len = $bb_cfg['search_max_word_len'];

#	$text = preg_replace('#\b\w{1,'. $min_word_len .'}\b#', '', $text);
#	$text = preg_replace('#\b\w{'. $max_word_len .',}\b#', '', $text);

	$text = ' '. str_compact(ltrim($text, $ltrim_chars)) .' ';

	if ($remove_stopwords)
	{
		$text = remove_stopwords($text);
	}

	if ($bb_cfg['search_engine_type'] == 'sphinx')
	{
		$text = preg_replace('#(?<=\S)\-#u', ' ', $text);                    // "1-2-3" -> "1 2 3"
		$text = preg_replace('#[^0-9a-zA-Zа-яА-ЯёЁ\-_*|]#u', ' ', $text);    // допустимые символы (кроме " которые отдельно)
		$text = str_replace('-', ' -', $text);                              // - только в начале слова
		$text = str_replace('*', '* ', $text);                              // * только в конце слова
		$text = preg_replace('#\s*\|\s*#u', '|', $text);                     // "| " -> "|"
		$text = preg_replace('#\|+#u', ' | ', $text);                        // "||" -> "|"
		$text = preg_replace('#(?<=\s)[\-*]+\s#u', ' ', $text);              // одиночные " - ", " * "
		$text = trim($text, ' -|');
		$text = str_compact($text);
		$text_match_sql = ($wrap_with_quotes && $text != '') ? '"'. $text .'"' : $text;
	}
	else
	{
#		if ($all_words)
#		{
#			$text = preg_replace('#\s(\b\w)#', ' +$1', $text);
#		}
		$text_match_sql = DB()->escape(trim($text));
	}

	if (!$text_match_sql && $die_if_empty)
	{
		bb_die($lang['NO_SEARCH_MATCH']);
	}
    return $text_match_sql;
}

function init_sphinx ()
{
	global $sphinx;

	if (!isset($sphinx))
	{
		require(INC_DIR .'sphinxapi.php');
		$sphinx = new SphinxClient();

		$sphinx->SetConnectTimeout(5);
#		$sphinx->SetMaxQueryTime(2);
		$sphinx->SetRankingMode(SPH_RANK_NONE);
		$sphinx->SetMatchMode(SPH_MATCH_BOOLEAN);
#		$sphinx->SetSortMode($mode, $sortby="");
	}
}

function log_sphinx_error ($err_type, $err_msg, $query = '')
{
	$ignore_err_txt = array(
		'negation on top level',
		'Query word length is less than min prefix length',
	);
	if (!count($ignore_err_txt) || !preg_match('#'. join('|', $ignore_err_txt) .'#i', $err_msg))
	{
		$orig_query = strtr($_REQUEST['nm'], array("\n" => '\n'));
		bb_log(date('m-d H:i:s') ." | $err_type | $err_msg | $orig_query | $query". LOG_LF, 'sphinx_error');
	}
}

function get_title_match_topics ($title_match_sql, $forum_ids = array())
{
	global $bb_cfg, $sphinx, $userdata, $title_match, $lang;

	$where_ids = array();
	if($forum_ids) $forum_ids = array_diff($forum_ids, array(0 => 0));
	$title_match_sql = encode_text_match($title_match_sql);

	if ($bb_cfg['search_engine_type'] == 'sphinx')
	{
		init_sphinx();

		$where = ($title_match) ? 'topics' : 'posts';

		$sphinx->SetServer($bb_cfg['sphinx_topic_titles_host'], $bb_cfg['sphinx_topic_titles_port']);
		if ($forum_ids)
		{
			$sphinx->SetFilter('forum_id', $forum_ids, false);
		}
		if (preg_match('#^"[^"]+"$#u', $title_match_sql))
		{
			$sphinx->SetMatchMode(SPH_MATCH_PHRASE);
		}
		if ($result = $sphinx->Query($title_match_sql, $where, $userdata['username'] .' ('. CLIENT_IP .')'))
		{
			if (!empty($result['matches']))
			{
				$where_ids = array_keys($result['matches']);
			}
		}
		else if ($error = $sphinx->GetLastError())
		{
			if (strpos($error, 'errno=110'))
			{
				bb_die($lang['SEARCH_ERROR']);
			}
			log_sphinx_error('ERR', $error, $title_match_sql);
		}
		if ($warning = $sphinx->GetLastWarning())
		{
			log_sphinx_error('wrn', $warning, $title_match_sql);
		}
	}
	else if ($bb_cfg['search_engine_type'] == 'mysql')
	{
		$where_forum = ($forum_ids) ? "AND forum_id IN(". join(',', $forum_ids) .")" : '';
		$search_bool_mode = ($bb_cfg['allow_search_in_bool_mode']) ? ' IN BOOLEAN MODE' : '';

		if($title_match)
		{
			$where_id = 'topic_id';
			$sql = "SELECT topic_id FROM ". BB_TOPICS ."
					WHERE MATCH (topic_title) AGAINST ('$title_match_sql'$search_bool_mode)
					$where_forum";
		}
		else
		{
			$where_id = 'post_id';
			$sql = "SELECT p.post_id FROM ". BB_POSTS ." p, ". BB_POSTS_SEARCH ." ps
				WHERE ps.post_id = p.post_id
					AND MATCH (ps.search_words) AGAINST ('$title_match_sql'$search_bool_mode)
					$where_forum";
		}

		foreach (DB()->fetch_rowset($sql) as $row)
		{
			$where_ids[] = $row[$where_id];
		}
	}
	else
	{
		bb_die($lang['SEARCH_OFF']);
	}

	return $where_ids;
}

// для более корректного поиска по словам содержащим одиночную кавычку
function encode_text_match ($txt)
{
	return str_replace("'", '&#039;', $txt);
}

function decode_text_match ($txt)
{
	return str_replace('&#039;', "'", $txt);
}

function remove_stopwords ($text)
{
	static $stopwords = null;

	if (is_null($stopwords))
	{
		$stopwords = explode(' ', str_compact(@file_get_contents(LANG_DIR .'search_stopwords.txt')));
		array_deep($stopwords, 'pad_with_space');
	}

	return ($stopwords) ? str_replace($stopwords, ' ', $text) : $text;
}

function pad_with_space ($str)
{
	return ($str) ? " $str " : $str;
}

function create_magnet($infohash, $auth_key, $logged_in)
{
	global $bb_cfg, $userdata, $_GET;
	$passkey_url = (!$logged_in || isset($_GET['no_passkey'])) ? '' : "?{$bb_cfg['passkey_key']}=$auth_key";
	return '<a href="magnet:?xt=urn:btih:'. bin2hex($infohash) .'&tr='. urlencode($bb_cfg['bt_announce_url'] . $passkey_url) .'"><img src="images/magnet.png" width="12" height="12" border="0" /></a>';
}

function get_avatar ($avatar, $type, $allow_avatar = true, $height = '', $width = '')
{
    global $bb_cfg, $lang;

    $height = ($height != '') ? 'height="'. $height .'"' : '';
	$width  = ($width != '') ? 'width="'. $width .'"' : '';

	$user_avatar = '<img src="'. $bb_cfg['no_avatar'] .'" alt="" border="0" '. $height .' '. $width .' />';


    if ($allow_avatar)
    {
        switch($type)
        {
            case USER_AVATAR_UPLOAD:
                $user_avatar = ( $bb_cfg['allow_avatar_upload'] ) ? '<img src="'. $bb_cfg['avatar_path'] .'/'. $avatar .'" alt="" border="0" '. $height .' '. $width .' />' : '';
                break;
            case USER_AVATAR_REMOTE:
                $user_avatar = ( $bb_cfg['allow_avatar_remote'] ) ? '<img src="'. $avatar .'" alt="" border="0" onload="imgFit(this, 100);" onClick="return imgFit(this, 100);" '. $height .' '. $width .' />' : '';
                break;
            case USER_AVATAR_GALLERY:
                $user_avatar = ( $bb_cfg['allow_avatar_local'] ) ? '<img src="'. $bb_cfg['avatar_gallery_path'] .'/'. $avatar .'" alt="" border="0" '. $height .' '. $width .' />' : '';
                break;
        }
    }
    return $user_avatar;
}

function set_die_append_msg ($forum_id = null, $topic_id = null)
{
	global $userdata, $lang, $template;
	
	$msg = '';
	$msg .= ($topic_id) ? '<p class="mrg_10"><a href="viewtopic.php?t='. $topic_id .'">'. $lang['CLICK_RETURN_TOPIC'] .'</a></p>' : '';
	$msg .= ($forum_id) ? '<p class="mrg_10"><a href="viewforum.php?f='. $forum_id .'">'. $lang['CLICK_RETURN_FORUM'] .'</a></p>' : '';
	$msg .= '<p class="mrg_10"><a href="index.php">'. $lang['CLICK_RETURN_INDEX'] .'</a></p>';
	$template->assign_var('BB_DIE_APPEND_MSG', $msg);
}

function CAPTCHA ()
{
	static $captcha_obj = null;

	if ($captcha_obj === null)
	{
		global $bb_cfg;
		require(INC_DIR .'captcha/captcha.php');
		$captcha_obj = new captcha_kcaptcha($bb_cfg['captcha']);
	}

	return $captcha_obj;
}

function get_path_from_id ($id, $ext_id, $base_path, $first_div, $sec_div)
{
	global $bb_cfg;
	$ext = isset($bb_cfg['file_id_ext'][$ext_id]) ? $bb_cfg['file_id_ext'][$ext_id] : '';
	return ($base_path ? "$base_path/" : '') . ($id % $sec_div) .'/'. $id . ($ext ? ".$ext" : '');
}

function send_pm($user_id, $subject, $message, $poster_id = false)
{
	global $userdata;

	$subject = DB()->escape($subject);
	$message = DB()->escape($message);

    if($poster_id == BOT_UID)
    {
    	$poster_ip = '7f000001';
    }
    else if($row = DB()->fetch_row("SELECT user_reg_ip FROM ". BB_USERS ." WHERE user_id = $poster_id"))
    {
    	$poster_ip = $row['user_reg_ip'];
    }
    else
    {
    	$poster_id = $userdata['user_id'];
    	$poster_ip = USER_IP;
    }

	DB()->sql_query("INSERT INTO ". BB_PRIVMSGS ." (privmsgs_type, privmsgs_subject, privmsgs_from_userid, privmsgs_to_userid, privmsgs_date, privmsgs_ip)
		VALUES (". PRIVMSGS_NEW_MAIL .", '$subject', {$poster_id}, $user_id, ". TIMENOW .", '$poster_ip')");
	$pm_id = DB()->sql_nextid();

	DB()->sql_query("INSERT INTO " . BB_PRIVMSGS_TEXT . " (privmsgs_text_id, privmsgs_text)
			VALUES ($pm_id, '$message')");

	DB()->sql_query("UPDATE ". BB_USERS ." SET
		user_new_privmsg = user_new_privmsg + 1,
		user_last_privmsg = ". TIMENOW .",
		user_newest_pm_id = $pm_id
		WHERE user_id = $user_id");
}

function profile_url($data)
{
	global $bb_cfg, $lang, $datastore;

	if (!$ranks = $datastore->get('ranks'))
	{
		$datastore->update('ranks');
		$ranks = $datastore->get('ranks');
	}

	$user_rank = !empty($data['user_rank']) ? $data['user_rank'] : 0;

	if(isset($ranks[$user_rank]))
	{		$title = $ranks[$user_rank]['rank_title'];		$style = $ranks[$user_rank]['rank_style'];
	}
	if(empty($title)) $title = $lang['USER'];
	if(empty($style)) $style = 'colorUser';

	if(!$bb_cfg['color_nick']) $style = '';

	$username = !empty($data['username']) ? $data['username'] : $lang['GUEST'];
	$user_id = (!empty($data['user_id']) && $username != $lang['GUEST']) ? $data['user_id'] : ANONYMOUS;

	$profile = '<span title="'. $title .'" class="'. $style .'">'. $username .'</span>';

	if(!in_array($user_id, array('', ANONYMOUS, BOT_UID)) && $username)
	{
		$profile = '<a href="'. make_url(PROFILE_URL . $user_id) .'">'. $profile .'</a>';
	}

	return $profile;
}