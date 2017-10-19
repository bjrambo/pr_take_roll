<?php
class pr_take_roll extends WidgetHandler
{
	private $UseCacheSec;
	private $AttendanceList;

	function proc($args)
	{
		$this->UseCacheSec = (int)$args->use_cache_sec;

		$widget_info = new stdClass();
		$widget_info->btn_color = $args->btn_color ? $args->btn_color : 'orange';
		$widget_info->colorset = $args->colorset ? $args->colorset : 'dark';
		$widget_info->list_count = (int)$args->list_count <= 0 ? 5 : (int)$args->list_count;
		if($widget_info->btn_color == 'random')
		{
			$btn_array = array('orange', 'blue', 'lred', 'red', 'green', 'dgrey', 'lpurple', 'purple');
			shuffle($btn_array);
			$widget_info->btn_color = $btn_array[0];
		}

		$show_list = $args->show_list ? $args->show_list : 'no';
		$order_type = $args->order_type == 'asc' ? 'asc' : 'desc';
			
		//출석자 리스트 모두보기일때
		if($show_list == 'all')
		{
			$this->setAttendanceList($order_type);
		}
			
		if(Context::get('is_logged'))
		{
			$logged_info = Context::get('logged_info');
			$widget_info->btn_act = $args->btn_act == 'link' ? 'link' : 'direct';

			$oAttendanceModel = getModel('attendance');
			$output = $oAttendanceModel->getTotalData($logged_info->member_srl);
			$widget_info->attendance->total = $output->total;
			$widget_info->attendance->continuity = $output->continuity;

			$is_checked = $oAttendanceModel->getIsChecked($logged_info->member_srl); //int (오늘날짜 출첵횟수)
			$is_available = $oAttendanceModel->availableCheck(); //bool(true-불가능, false-가능) 7.0.1이전 버전은 1 or 0
			$config = $oAttendanceModel->getConfig();

			//인사말설정
			if($args->greeting == 'rand' && $config->greeting_list)
			{
				$greeting_list = explode("\r\n", $config->greeting_list);
				shuffle($greeting_list);
				$widget_info->greeting_name = $greeting_list[0];
			}

			if($show_list == 'loggedin' && !$this->AttendanceList)
			{
				$this->setAttendanceList($order_type);
			}

			if($is_checked == 0)
			{
				if($config->about_admin_check == 'no' && $logged_info->is_admin == 'Y')
				{
					$widget_info->status = 3; //Admin은 출첵 못함
				}
				else
				{
					if(!$is_available)
					{
						$widget_info->status = 0; //출석 가능
					}
					else
					{
						$widget_info->status = 1; //출석 가능시간 아님
					}
				}
			}
			else
			{
				$widget_info->status = 2; //이미 출석 했음
				
				//출석 했으면 랭킹 구함: $this->AttendanceList가 비어있으면 set먼저 시도
				if(!$this->AttendanceList)
				{
					$this->setAttendanceList($order_type);
				}
				$widget_info->attendance->rank = $this->getRankingByMemberSrl($logged_info->member_srl);
			}

			//출석 권한 확인
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

			/* 관리자에게는 추가정보 보여줌(출첵인원/로그인인원/사이트의 총회원수) */
			if($logged_info->is_admin == 'Y' && $args->admin_info !== 'no')
			{
				$widget_info->admin_info = $this->getAdminInfo();
			}
		}

		//목록 안보기가 아니면 AttendanceList가 set된 상태이므로 html로 변환 해 준다.
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


	/* 관리자에게 제공할 추가정보를 html로 리턴, 캐시사용 */
	function getAdminInfo()
	{
		//캐시키 설정
		$cache_key = 'widget_pr_take_roll:AdminInfo';

		//캐시가 있다면 캐시 사용
		if($oCacheHandler = $this->getCacheHandler())
		{
			if(($result = $oCacheHandler->get($cache_key, time() - $this->UseCacheSec)) !== false)
			{
				return $result;
			}
		}

		$obj = new stdClass();
		$obj->last_login_Ymd = date('Ymd');
		$output = executeQuery('widgets.pr_take_roll.getMemberCount', $obj);
		if($output->toBool())
		{
			$result = '<span class="member_info"><i class="fa fa-check-square-o" aria-hidden="true"></i> ' . number_format($output->data->takeroll);
			$result .= ' <i class="fa fa-sign-in" aria-hidden="true"></i> ' . number_format($output->data->signup);
			$result .= ' <i class="fa fa-user-circle-o" aria-hidden="true"></i> ' . number_format($output->data->total);
			$result .= '</span>';

			//기존 캐시 폐기 되었으므로 다시 캐시 저장
			if($oCacheHandler)
			{
				$oCacheHandler->put($cache_key, $result, $this->UseCacheSec);
			}
		}

		return $result;
	}

	/* 유저의 출석 순위 구하기: 캐시로 인해 지연될수 있어서 '집계중'추가 */
	function getRankingByMemberSrl($member_srl)
	{
		$result = '집계중';
		foreach($this->AttendanceList as $key => $value)
		{
			if ($member_srl == $value->member_srl)
			{
				$result = number_format($key + 1) . '위';
				break;
			}
		}
		return $result;
	}

	/* 출석자 리스트를 요구한 형태, 수량 만큼 html로 출력 */
	function getAttendanceListToHtml($args, $list_count)
	{
		$result = new stdClass();
		$result->lists = array();
		if($this->AttendanceList)
		{
			$max_count = count($this->AttendanceList);
			if($args->page_navi !== 'yes' && $max_count > $list_count)
			{
				$max_count = $list_count;
			}

			$count = 0;
			foreach($this->AttendanceList as $key => $val)
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

	/* 출석자 리스트 리턴: 유효한 캐시가 있다면 캐시 사용 */
	function setAttendanceList($order_type = 'asc')
	{
		//캐시키 설정
		$cache_key = 'widget_pr_take_roll:AttendanceList';

		//캐시가 있다면 캐시 사용
		if($oCacheHandler = $this->getCacheHandler())
		{
			if(($result = $oCacheHandler->get($cache_key, time() - $this->UseCacheSec)) !== false)
			{
				$this->AttendanceList = $result;
				return;
			}
		}

		//쿼리해서 리스트 작성
		$obj = new stdClass();
		$obj->order_type = 'asc'; //오름차순으로 불러옴, 리턴된 Array의 키값을 순위로 사용하기 위함.
		$obj->today_Ymd = date('Ymd');
		$output = executeQueryArray('widgets.pr_take_roll.getAllAttendanceList', $obj);
		if(!$output->data) 
		{
			$output->data = array();
		}

		//프로필 이미지 지정
		$exts = array('gif', 'jpg', 'png');
		foreach($output->data as $key => $value)
		{
			for($i = 0; $i < 3; $i++)
			{
				$image_name_file = sprintf('files/member_extra_info/profile_image/%s%d.%s', getNumberingPath($value->member_srl), $value->member_srl, $exts[$i]);
				if(file_exists($image_name_file))
				{
					$output->data[$key]->profile_src = Context::getRequestUri().$image_name_file . '?' . date('YmdHis', filemtime($image_name_file));
					break;
				}
			}
			//프로필 이미지가 없는 사용자의경우 기본 이미지로 지정
			if(!$output->data[$key]->profile_src)
			{
				$output->data[$key]->profile_src = Context::getRequestUri() . 'widgets/pr_take_roll/profile/default.png';
			}
		}

		//내림차순 출력 요청이면 배열 재 정렬
		if($order_type == 'desc')
		{
			krsort($output->data);
		}

		//여기까지 진행했고 캐시 핸들러가 있다면 기존 캐시 폐기 되었으므로 다시 캐시 저장
		if($oCacheHandler && count($output->data) > 0)
		{
			$oCacheHandler->put($cache_key, $output->data, $this->UseCacheSec);
		}

		$this->AttendanceList = $output->data;
	}

	/* 설정에 따라 캐시 핸들러 리턴 */
	function getCacheHandler()
	{
		static $oCacheHandler = null;
		if($oCacheHandler === null)
		{
			if($this->UseCacheSec == 0)
			{
				$oCacheHandler = false;
			}
			else
			{
				$oCacheHandler = CacheHandler::getInstance('object');
				if(!$oCacheHandler->isSupport())
				{
					$oCacheHandler = false;
				}
			}
		}
		return $oCacheHandler;
	}
}
