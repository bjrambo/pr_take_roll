<?php
class pr_take_roll extends WidgetHandler
{
	private $attendanceList;

	function proc($args)
	{
		$widget_info = new stdClass();
		$widget_info->btn_color = $args->btn_color ? $args->btn_color : 'orange';
		$widget_info->colorset = $args->colorset ? $args->colorset : 'dark';
		$widget_info->list_count = (int)$args->list_count <= 0 ? 5 : (int)$args->list_count;
		if($widget_info->btn_color == 'random')
		{
			$btn_array = array('orange', 'blue', 'lred', 'red', 'green', 'dgrey', 'lpurple', 'purple');
			$randNumber = mt_rand(0, count($btn_array) - 1);
			$widget_info->btn_color = $btn_array[$randNumber];
		}

		$show_list = $args->show_list ? $args->show_list : 'no';
		$order_type = $args->order_type == 'asc' ? 'asc' : 'desc';

		if($show_list == 'all')
		{
			$this->setAttendanceList($order_type);
		}

		if(Context::get('is_logged'))
		{
			$logged_info = Context::get('logged_info');
			$widget_info->btn_act = $args->btn_act == 'link' ? 'link' : 'direct';

			/** @var $oAttendanceModel attendanceModel */
			$oAttendanceModel = getModel('attendance');
			$output = $oAttendanceModel->getTotalData($logged_info->member_srl);
			$widget_info->attendance->total = $output->total;
			$widget_info->attendance->continuity = $output->continuity;

			$is_checked = $oAttendanceModel->getIsChecked($logged_info->member_srl);
			//bool(true-불가능, false-가능) 7.0.3이전 버전은 1 or 0
			$is_available = $oAttendanceModel->availableCheck();
			$config = $oAttendanceModel->getConfig();

			if($args->greeting == 'rand' && $config->greeting_list)
			{
				$greeting_list = explode("\r\n", $config->greeting_list);
				$greetingNumber = mt_rand(0, count($greeting_list) - 1);
				$widget_info->greeting_name = $greeting_list[$greetingNumber];
			}

			if($show_list == 'loggedin' && !$this->attendanceList)
			{
				$this->setAttendanceList($order_type);
			}

			if($is_checked == 0)
			{
				if($config->about_admin_check == 'no' && $logged_info->is_admin == 'Y')
				{
					// Admin은 출첵 못함
					$widget_info->status = 3;
				}
				else
				{
					if(!$is_available)
					{
						// 출석 가능
						$widget_info->status = 0;
					}
					else
					{
						// 출석 가능시간 아님
						$widget_info->status = 1;
					}
				}
			}
			else
			{
				// 이미 출석 했음
				$widget_info->status = 2;

				if(!$this->attendanceList)
				{
					$this->setAttendanceList($order_type);
				}
				$widget_info->attendance->rank = $this->getRankingByMemberSrl($logged_info->member_srl);
			}

			if(!$args->group_srls)
			{
				$widget_info->permission = true;
			}
			else
			{
				$widget_info->permission = false;
				$group_array = explode (',', $args->group_srls);

				foreach($logged_info->group_list as $key => $value)
				{
					if(in_array($key, $group_array))
					{
						$widget_info->permission = true;
						break;
					}
				}
			}

			if($logged_info->is_admin == 'Y' && $args->admin_info !== 'no')
			{
				$widget_info->admin_info = $this->getAdminInfo();
			}
		}

		if($show_list !== 'no')
		{
			$widget_info->data = $this->getAttendanceListToHtml($args, $widget_info->list_count);
		}

		Context::set('widget_info', $widget_info);

		// Compile a template
		$tpl_path = sprintf('%sskins/%s', $this->widget_path, $args->skin);
		$oTemplate = TemplateHandler::getInstance();
		return $oTemplate->compile($tpl_path, 'default');
	}

	/**
	 * Get to the admin data compile to html. If set to cache data, use the cache.
	 * @return mixed
	 */
	function getAdminInfo()
	{
		$today = date('Ymd');
		$cache_key = "widget_pr_take_roll:AdminInfo:today:$today";

		if($oCacheHandler = $this->getCacheHandler())
		{
			if(($result = $oCacheHandler->get($oCacheHandler->getGroupKey('widget_pr_take_roll', $cache_key), time() - 86400)) !== false)
			{
				return $result;
			}
		}

		$args = new stdClass();
		$args->last_login_Ymd = date('Ymd');
		$output = executeQuery('widgets.pr_take_roll.getMemberCount', $args);
		if($output->toBool())
		{
			$result = '<span class="member_info"><i class="fa fa-check-square-o" aria-hidden="true"></i> ' . number_format($output->data->takeroll);
			$result .= ' <i class="fa fa-sign-in" aria-hidden="true"></i> ' . number_format($output->data->signup);
			$result .= ' <i class="fa fa-user-circle-o" aria-hidden="true"></i> ' . number_format($output->data->total);
			$result .= '</span>';

			if($oCacheHandler)
			{
				$oCacheHandler->put($oCacheHandler->getGroupKey('widget_pr_take_roll', $cache_key), $result, 86400);
			}
		}
		else
		{
			$result = false;
		}

		return $result;
	}

	/**
	 * Get to the member Ranking in attendance.
	 * If do not have a member ranking data, display to string(집계중).
	 * @param $member_srl
	 * @return string
	 */
	function getRankingByMemberSrl($member_srl)
	{
		$result = '집계중';
		foreach($this->attendanceList as $key => $value)
		{
			if ($member_srl == $value->member_srl)
			{
				$result = number_format($key + 1) . '위';
				break;
			}
		}
		return $result;
	}

	/**
	 * Get to the attendance list compile to HTML.
	 * @param $args object
	 * @param $list_count int
	 * @return stdClass
	 */
	function getAttendanceListToHtml($args, $list_count)
	{
		$result = new stdClass();
		$result->lists = array();
		if($this->attendanceList)
		{
			$max_count = count($this->attendanceList);
			if($args->page_navi !== 'yes' && $max_count > $list_count)
			{
				$max_count = $list_count;
			}

			$count = 0;
			foreach($this->attendanceList as $key => $val)
			{
				if($count++ >= $max_count)
					break;
				$tmp = '<span class="rank">' . number_format($key + 1) . '위</span>';
				$tmp .= '<span class="profile_img"><img src="' . $val->profile_src . '"></span>';
				$tmp .= '<span class="nick_name">' . $val->nick_name . '</span>';
				$tmp .= '<span class="point">' . number_format($val->today_point) . 'P</span>';
				if($args->list_greeting === 'yes')
				{
					$tmp .= '<span class="greeting">' . $val->greetings . '</span>';
				}
				if($args->list_conti !== 'no')
				{
					$tmp .= '<span class="conti">연속 ' . number_format($val->continuity) . '일</span>';
				}
				if($args->list_total !== 'no')
				{
					$tmp .= '<span class="total">총 ' . number_format($val->total) . '일</span>';
				}
				$result->lists[] = $tmp;
			}
		}

		$result->is_page = count($result->lists) > $list_count ? true : false;
		return $result;
	}

	/**
	 * Get to the Attendance List.
	 * @param string $order_type
	 */
	function setAttendanceList($order_type = 'asc')
	{
		$today = date('Ymd');
		$cache_key = "widget_pr_take_roll:AttendanceList:orderType:$order_type:todayYmd:$today";

		if($oCacheHandler = $this->getCacheHandler())
		{
			if(($result = $oCacheHandler->get($oCacheHandler->getGroupKey('widget_pr_take_roll', $cache_key), time() - 86400)) !== false)
			{
				$this->attendanceList = $result;
				return;
			}
		}

		$args = new stdClass();
		$args->order_type = $order_type;
		$args->today_Ymd = $today;
		$output = executeQueryArray('widgets.pr_take_roll.getAllAttendanceList', $args);
		if(!$output->data)
		{
			$output->data = array();
		}

		/** @var $oMemberModel memberModel */
		$oMemberModel = getModel('member');
		foreach($output->data as $key => $value)
		{
			$image_name_file = $oMemberModel->getProfileImage($value->member_srl);

			$output->data[$key]->profile_src = $image_name_file;
			if(!$output->data[$key]->profile_src)
			{
				$output->data[$key]->profile_src = '/widgets/pr_take_roll/profile/default.png';
			}
		}

		if($oCacheHandler && count($output->data) > 0)
		{
			$oCacheHandler->put($oCacheHandler->getGroupKey('widget_pr_take_roll', $cache_key), $output->data, 86400);
		}

		$this->attendanceList = $output->data;
	}

	/**
	 * Get to the cache config. if use the cache, return to cache handler.
	 * @return bool|CacheHandler|null
	 */
	function getCacheHandler()
	{
		/** @var $oAttendanceModel attendanceModel */
		$oAttendanceModel = getModel('attendance');
		$oCacheHandler = $oAttendanceModel->getCacheHandler();

		return $oCacheHandler;
	}
}
