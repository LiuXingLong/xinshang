<?php
/**
 * Desc: 站内信
 * User: lxl
 * Date: 2016/12/13
 */
namespace app\common\logic;
use think\Db;
// 站内信
class StationMsg {
    private static $Admin_id = 0;    // 管理员 ID
    private static $Admin_group = 0; // 管理员组 ID
    protected static $_error_code = array(
            '01' => '发送成功!',
            '02' => '发送失败!',
            '03' => '发送内容不能为空！',
            '04' => '群发组不能为空！',
            '05' => '发送用户id不能为空！',
            '06' => '不能给自己发送!',
            '07' => '发送用户不能为空!',
            '08' => '发送用户不存在!',
            '09' => '发送用户名不能为空！'
    );
    
    /**
     * 站内信消息发送  
     * 发送方式：对组群发，对用户选择发送 ， 单个用户之间发送
     * @param $send_id        发送用户id
     * @param $rec_id         接收用户id（也可为接收用户组）  或  群发组 id
     * @param $content        发送内容
     * @param $username       发送人用户名
     * @param $mobile         发送人手机号
     * @param $nickname       发送人用户昵称 或店铺昵称，优先店铺昵称
     * @param $logo           发送人用户头像 或 店铺头像，优先店铺头像
     * @param $title          发送标题
     * @param $is_group_send  是否群发     群发  true ，不群发 false
     * 特别注意：给所有人发时，属于对组发送，且发送组 $rec_id == 0
     */
    public static function SendMsg($send_id, $rec_id,$content,$username,$mobile = null,$logo = null,$nickname = null,$title = null, $is_group_send = false)
    {
        $send_id = (int)$send_id;
        $rec_id = (int)$rec_id;
        // 初始化返回结果
        $result = array(
                'status' => false,
                   'msg' => false
        );
        
        // 检测发送内容是否为空
        $content = trim($content);
        if(empty($content)){
            $result['msg'] = self::$_error_code['03'];
            return $result;
        }
        
        // 检测接收用户
        if(empty($rec_id) && $rec_id !== self::$Admin_id )
        {
            if($is_group_send){
                $result['msg'] = self::$_error_code['04'];
            }else{
                $result['msg'] = self::$_error_code['05'];
            }
            return $result;
        }else if( $rec_id !== self::$Admin_id ){
            if(!$is_group_send){
                if(is_array($rec_id) && in_array($send_id, $rec_id)){
                    $result['msg'] = self::$_error_code['06'];
                }elseif(!is_array($rec_id) && $rec_id == $send_id){
                    $result['msg'] = self::$_error_code['06'];
                }
                if($result['msg']){
                    return $result;
                }
            }
        }
        
        // 检测发送用户
        if(empty($send_id) && $send_id !== self::$Admin_id ){
            $result['msg'] = self::$_error_code['07'];
            return $result;
        }else if($send_id !== self::$Admin_id ){
            $map['id'] = $send_id;
            $user = db('user')->where($map)->find();
            if(!$user)
            {
                $result['msg'] = self::$_error_code['08'];
                return $result;
            }
        }
        // 检查发送用户名
        if(empty($username)){
            $result['msg'] = self::$_error_code['09'];
            return $result;
        }
        // 处理用户昵称
        if(empty($nickname)){
            $nickname = $username;
        }
        // 处理发送标题
        if(empty($title)){
            $title = self::substrMy($content,0,40);
        }
        // 处理发送内容中所含链接
        $regex = '/((http:\/\/|www\.|https:\/\/)(\w+|\.|\?|\=|\/|\&|\:|\d+)+)/';
        $content = preg_replace_callback($regex,function($matches){
                        if(!empty($matches[0]) && (strstr($matches[0],'http://')||strstr($matches[0],'https://'))){
                            return '<a href="'.$matches[0].'" target="_blank">'.$matches[0].'</a>';
                        }else{
                            return '<a href="http://'.$matches[0].'" target="_blank">'.$matches[0].'</a>';
                        }
                   },$content);
        
        $data = array();
        $data['send_id'] = $send_id;
        $data['title'] = $title;
        $data['content'] = $content;
        $data['username'] = $username;
        $data['nickname'] = $nickname;
        $data['mobile'] = $mobile;
        $data['logo'] = $logo;
        $data['w_time'] = systemTime();
        
        // 发送消息
        if($is_group_send)
        {
            // 群发消息
            $data['type'] = 2;          // 给一个组发送
            $data['rec_id'] = $rec_id;  // 组id
            $rs = Db::table('station_news')->insert($data);
            if($rs){
                $result['status'] = true;
                $result['msg'] = self::$_error_code['01'];
            }else{
                $result['msg'] = self::$_error_code['02'];
            }
        }
        else
        {
            // 选择用户发送
            $data['type'] = 1;
            if(is_array($rec_id))
            {
                // 开启事务      给多个用户发送
                Db::startTrans();
                foreach($rec_id as $val){
                    $data['rec_id'] = $val;
                    $rs = Db::table('station_news')->insert($data);
                    if(!$rs){
                        // 回滚事务
                        Db::rollback();
                        $result['msg'] = self::$_error_code['02'];
                        return $result;
                    }
                }
                // 提交事务
                Db::commit();
                if(!$result['msg']){
                    $result['status'] = true;
                    $result['msg'] = self::$_error_code['01'];
                }
            }
            else
            {   // 给单个用户发送
                $data['rec_id'] = $rec_id;
                $rs = Db::table('station_news')->insert($data);
                if($rs){
                    $result['status'] = true;
                    $result['msg'] = self::$_error_code['01'];
                }else{
                    $result['msg'] = self::$_error_code['02'];
                }
            }
        }
        return $result;
    }
    
    /**
     * 消息列表
     * 读取分组列表，按用户分组，显示最后一条数据，且含有未读消息的显示未读消息条数 
     * @param  $user_id     用户id
     * @param  $user_group  用户所在组 id 或 数组
     * @param  $page        页数
     * @param  $number      每页数量
     * 特别提醒：采用左连接查询，查询前传入的  $userid，$user_group 必须是查询用户自身的
     */
    public static function noReadList($user_id ,$user_group ,$page = null ,$number = null )
    {
        $user_id = (int)$user_id;
        // 原生SQL语句   SELECT `a`.*,count(a.msg_id) - count(b.msg_id) as num FROM ( SELECT `msg_id`,`send_id`,`title`,`time` FROM `station_news` WHERE  `send_id` <> 123456  AND (  (  `rec_id` = 123456  AND `type` = 1 ) OR (  `rec_id` IN (222,555)  AND `type` = 2 )  OR `rec_id` = 0 ) ORDER BY time desc         ) `a` LEFT JOIN ( SELECT `msg_id` FROM `station_news_status` WHERE  `user_id` = 123456   ) `b` ON `a`.`msg_id`=`b`.`msg_id` GROUP BY a.send_id;
        if ( (empty($user_id) && $user_id !== self::$Admin_id) || empty($user_group) || !is_array($user_group)) {
            return array('status' => false,'msg' => '参数错误！');
        }
        
        $data = array();
        $data['user_id'] = $user_id;
        $data['user_group'] = $user_group;
        
        // 构造查找 station_news 表中属于自己所有消息的SQL语句  （包含已读和未读的）
        $subsql_a = Db::table('station_news')
                        ->where('send_id','neq',$data['user_id'])
                        ->where(function($query)use($data){$query
                            ->where(function($query)use($data){
                                $query->where(['rec_id' => $data['user_id'] , 'type' => 1]);
                            })
                            ->whereor(function($query)use($data){
                                $query->where('rec_id','in', $data['user_group'])->where('type', 2);
                            });
                        })
                        ->field('msg_id,send_id,nickname,logo,title,w_time')
                        ->order('w_time desc')
                        ->buildSql();
                        
        // 构造查找 station_news_status 表中属于自己所有消息的SQL语句 （都为已读过的）
        $subsql_b = Db::table('station_news_status')
                        ->where('user_id',$data['user_id'])
                        ->field('msg_id')
                        ->buildSql();
        
        //  station_news 左连接  station_news_status 查出按用户分组的列表，含未读消息的显示未读消息条数
        $rs = null;
        if((empty($page) && $page!==0) || empty($number)){
            $rs = Db::table([$subsql_a => 'a'])
                    ->field('a.*,count(a.msg_id) - count(b.msg_id) as num')
                    ->join([$subsql_b => 'b'],'a.msg_id = b.msg_id','LEFT')
                    ->group('a.send_id')
                    ->order('a.w_time desc')
                    ->fetchSql(false)
                    ->select();
        }else{
            $data['page'] = $page * $number;
            $data['number'] = $number;
            $rs = Db::table([$subsql_a => 'a'])
                    ->field('a.*,count(a.msg_id) - count(b.msg_id) as num')
                    ->join([$subsql_b => 'b'],'a.msg_id = b.msg_id','LEFT')
                    ->group('a.send_id')
                    ->order('a.w_time desc')
                    ->limit($data['page'],$data['number'])
                    ->fetchSql(false)
                    ->select();
        }
        if (empty($rs)) {
            return array('status' => false,'msg' => '没有消息！','data' => null);
        } else {
            return array('status' => true,'msg' => '读取消息列表成功！','data' => $rs);
        }
    }
    
    /**
     * 获取（所有用户发给自己的）的未读消息数量
     * @param $user_id    接收用户id
     * @param $user_group 接收用户组id
     */
    public static function allNoReadMsgCount($user_id ,$user_group)
    {
        $user_id = (int)$user_id;
        if( ( empty($user_id) && $user_id !== self::$Admin_id ) || empty($user_group) || !is_array($user_group) ){
            return array('status' => false,'msg' => '参数错误！');
        }
        $data = array();
        $data['user_id'] = $user_id;
        $data['user_group'] = $user_group;
    
        // 构造查找 station_news 表中属于自己所有消息的SQL语句  （包含已读和未读的）
        $subsql_a = Db::table('station_news')
                        ->where('send_id','neq',$data['user_id'])
                        ->where(function($query)use($data){$query
                            ->where(function($query)use($data){
                                $query->where(['rec_id' => $data['user_id'] , 'type' => 1]);
                            })
                            ->whereor(function($query)use($data){
                                $query->where('rec_id','in', $data['user_group'])->where('type', 2);
                            });
                        })
                        ->field('msg_id,send_id')
                        ->buildSql();
                        
        // 构造查找 station_news_status 表中属于自己所有消息的SQL语句 （都为已读过的）
        $subsql_b = Db::table('station_news_status')
                        ->where('user_id',$data['user_id'])
                        ->field('msg_id')
                        ->buildSql();
        
        //  station_news 左连接  station_news_status 找出未读消息数量
        $rs = Db::table([$subsql_a => 'a'])
                    ->field('count(a.msg_id) as num')
                    ->join([$subsql_b => 'b'],'a.msg_id = b.msg_id','LEFT')
                    ->where('b.msg_id is null')
                    ->fetchSql(false)
                    ->select();
        
        if(!empty($rs)) {
            return array('status' => true,'msg' => '查询所有未读条数成功！', 'num' => $rs[0]['num']);
        } else {
            return array('status' => false, 'msg' => '查询所有未读条数失败！','num' => null);
        }
    }
    
    /**
     * 获取（指定用户发送自己的）的未读消息     时间降序数据
     * @param $send_id 发送用户id
     * @param $user_id 接收用户id
     * @param $user_group 接收用户组id
     */
    public static function noReadMsg($send_id ,$user_id ,$user_group)
    {
        $send_id = (int)$send_id;
        $user_id = (int)$user_id;
        if( (empty($send_id) && $send_id !== self::$Admin_id) || ( empty($user_id) && $user_id !== self::$Admin_id ) || empty($user_group) || !is_array($user_group) ){
            return array('status' => false,'msg' => '参数错误！');
        }
        $data = array();
        $data['send_id'] = $send_id;
        $data['user_id'] = $user_id;
        $data['user_group'] = $user_group;
        
        // 构造查找 station_news 表中 $send_id（发送人） 发送给自己所有消息的SQL语句  （包含已读和未读的）
        $subsql_a = Db::table('station_news')
                        ->where(function($query)use($data){$query
                            ->where('send_id',$data['send_id'])
                            ->where(function($query)use($data){$query
                                ->where(function($query)use($data){
                                    $query->where(['rec_id' => $data['user_id'] , 'type' => 1 ]);
                                })
                                ->whereor(function($query)use($data){
                                    $query->where('rec_id','in', $data['user_group'])->where('type', 2);
                                });
                            });
                        })
                        ->field('msg_id,send_id,w_time,content')
                        ->order('w_time desc')
                        ->buildSql();
                        
        // 构造查找 station_news_status 表中$send_id(发送人) 发送给自己所有消息的SQL语句 （都为已读过的）
        $subsql_b = Db::table('station_news_status')
                        ->where(['send_id' => $data['send_id'] ,'user_id' => $data['user_id']])
                        ->field('msg_id')
                        ->buildSql();
                        
        // station_news 左连接  station_news_status 查出   $send_id(发送人) 发给自己未读过得消息
        $msg_data = Db::table([$subsql_a => 'a'])
                        ->field('a.*')
                        ->join([$subsql_b => 'b'],'a.msg_id = b.msg_id','LEFT')
                        ->where('b.msg_id is null')
                        ->select();
        if(!empty($msg_data)) {
            return array('status' => true,'msg' => '读取未读消息成功！', 'data' => $msg_data);
        } else {
            return array('status' => false, 'msg' => '没有未读消息！','data' => null);
        }
    }
    
    
    /*
            特别提醒：
     updateNoReadMsg  HistoryMsg  PollMsg   这三个函数存在时间轴关系，选择消息列表中的用户后，
            首先需要更新未读消息调用（updateNoReadMsg），
            然后再调用一次（HistoryMsg）获取最新的消息，之后开启轮询不断调用（PollMsg）读取最新消息。
            如果要再要获取历史消息调用（HistoryMsg）并传入正确的获取第几页 page 参数，所以用户在使用时，需要记录之前获取的历史记录是多少页了
    */
    
    
    /**
     * 更新发送者未读消息
     * 用户（$user_id）点击查看某个发送者（$send_id）的未读消息后，将未读消息插入状态表（station_news_status）中 
     * @param $send_id      发送者用户id
     * @param $user_id      读取用户id
     * @param $user_group   读取用户所在组
     * @param $startTime    更新开始时间
     * @param $endTime      更新结束时间
     * @return array        插入操作反馈信息 
     */
    public static function updateNoReadMsg ($send_id ,$user_id ,$user_group ,$startTime = null ,$endTime = null) 
    { 
        // 原生SQL select a.* from (select msg_id,send_id,rec_id,type from station_news where ((send_id = 888888) and ((rec_id = 123456 and type = 1) or (rec_id in (555,222) and type = 2) or (rec_id = 0)))) as a left join (select msg_id from status_news_status where (send_id = 888888 and user_id = 123456)) as b on a.msg_id = b.msg_id where  b.msg_id is null;
        $send_id = (int)$send_id;
        $user_id = (int)$user_id;
        if( (empty($send_id) && $send_id !== self::$Admin_id) || ( empty($user_id) && $user_id !== self::$Admin_id ) || empty($user_group) || !is_array($user_group) ){
            return array('status' => false,'msg' => '参数错误！');
        }
        // 站内未读消息更新时间
        $stationupdate_time = session('stationupdate_time');
        if(empty($stationupdate_time)){
            $time = systemTime();
            session('stationupdate_time',$time);
        }

        $data = array();
        $data['send_id'] = $send_id;
        $data['user_id'] = $user_id;
        $data['user_group'] = $user_group;
        
        // 构造查找 station_news 表中 $send_id（发送人） 发送给自己所有消息的SQL语句  （包含已读和未读的）
        if(empty($startTime) && empty($endTime)){
            $subsql_a = Db::table('station_news')
                            ->where(function($query)use($data){$query
                                ->where('send_id',$data['send_id'])
                                ->where(function($query)use($data){$query
                                    ->where(function($query)use($data){
                                        $query->where(['rec_id' => $data['user_id'] , 'type' => 1 ]);
                                    })
                                    ->whereor(function($query)use($data){
                                        $query->where('rec_id','in', $data['user_group'])->where('type', 2);
                                    });
                                });
                            })
                            ->field('msg_id,send_id,rec_id,type')
                            ->buildSql();
        } else {
            $data['w_time'] = [$startTime,$endTime];
            $subsql_a = Db::table('station_news')
                            ->where(function($query)use($data){$query
                                ->where('send_id',$data['send_id'])
                                ->where('w_time','between',$data['w_time'])
                                ->where(function($query)use($data){$query
                                    ->where(function($query)use($data){
                                        $query->where(['rec_id' => $data['user_id'] , 'type' => 1 ]);
                                    })
                                    ->whereor(function($query)use($data){
                                        $query->where('rec_id','in', $data['user_group'])->where('type', 2);
                                    });
                                });
                            })
                            ->field('msg_id,send_id,rec_id,type')
                            ->buildSql();
        }
        
        // 构造查找 station_news_status 表中$send_id(发送人) 发送给自己所有消息的SQL语句 （都为已读过的）
        $subsql_b = Db::table('station_news_status')
                        ->where(['send_id' => $data['send_id'] ,'user_id' => $data['user_id']])
                        ->field('msg_id')
                        ->buildSql();
        
        // station_news 左连接  station_news_status 查出   $send_id(发送人) 发给自己未读过得消息
        $msg_data = Db::table([$subsql_a => 'a'])
                        ->field('a.*')
                        ->join([$subsql_b => 'b'],'a.msg_id = b.msg_id','LEFT')
                        ->where('b.msg_id is null')
                        ->fetchSql(false)
                        ->select();
        
        if(!empty($msg_data)) {
            $nowtime = systemTime();
            foreach($msg_data as &$val){
                $val['user_id'] = $data['user_id'];
                $val['w_time'] = $nowtime;
            }
            // 将 $send_id(发送人) 发给自己未读的消息添加到 station_news_status（消息状态表）
            $rs = Db::table('station_news_status')->insertAll($msg_data);
            if($rs){
                return array('status' => true, 'msg' => '更新'.$rs.'未读消息!');
            }else{
                return array('status' => false, 'msg' => '更新未读消息失败！');
            }
        } else {
            return array('status' => 0, 'msg' => '没有未读消息！');
        }
    }
    
    /**
     * 向下拉取历史消息                 返回按时间降序排列的数据
     * @param $send_id    发送方id
     * @param $send_group 发送方组
     * @param $user_id    接收用户id
     * @param $user_group 接收用户组id
     * @param $page       显示第几页数据
     * @param $number     每页数据条数
     */
    public static function HistoryMsg($send_id ,$send_group ,$user_id ,$user_group ,$page = 0 ,$number = 20 )
    {
        $send_id = (int)$send_id;
        $user_id = (int)$user_id;
        if( (empty($send_id) && $send_id !== self::$Admin_id) || empty($send_group) || !is_array($send_group) || (empty($user_id) && $user_id !== self::$Admin_id) || empty($user_group) || !is_array($user_group) ){
            return array('status' => false,'msg' => '参数错误！');
        }
        
        $data = array();
        $stationMsg_time = session('stationMsg_time');
        if(empty($stationMsg_time)){
            // 设置即时消息和历史消息时间分界线
            $time = systemTime();
            session('stationMsg_time',$time);
            session('stationMsg_polltime',$time);
        }
        $data[] = session('stationMsg_time');
        $data[] = $send_id;
        $data[] = $user_id;
        $data = array_merge($data,$user_group);
        $data[] = $user_id;
        $data[] = $send_id;
        $data = array_merge($data,$send_group);
        $data[] = $number * $page;
        $data[] = $number;
        
        // 查询发送方与接收方之间的互动历史消息
        $user_bindstr = str_repeat('?,', count($user_group) - 1) . '?';
        $send_bindstr = str_repeat('?,', count($send_group) - 1) . '?';
        $rs = Db::query('select send_id,rec_id,type,content,w_time from station_news where (w_time <= ?) and ( ((send_id = ?) and ((type = 1 and rec_id = ?) or (type = 2 and rec_id in (' . $user_bindstr . ')))) or ((send_id = ?) and ((type = 1 and rec_id = ?) or (type = 2 and rec_id in (' . $send_bindstr . ')))) ) order by w_time desc limit ?, ?',$data);
        if(!$rs){
            return array('status' => false,'msg' => '没有更多消息了！','data' => null);
        }else{
            return array('status' => true,'msg' => '查询成功！','data' => $rs);
        }
    }
    
    /**
     * 获取轮询消息    时间升序数据
     * 轮询获取对话框即时发送消息，   并将对方发给自己的消息标记已读
     * @param $send_id    发送方id
     * @param $send_group 发送方组id
     * @param $user_id    接收用户id
     * @param $user_group 接收用户组id
     */
    public static function PollMsg($send_id ,$send_group ,$user_id ,$user_group)
    {
        $send_id = (int)$send_id;
        $user_id = (int)$user_id;
        if( ( empty($send_id) && $send_id !== self::$Admin_id ) || empty($send_group) || !is_array($send_group) || ( empty($user_id) && $user_id !== self::$Admin_id ) || empty($user_group) || !is_array($user_group) ){
            return array('status' => false,'msg' => '参数错误！');
        }
        $data = array();
        $data[] = session('stationMsg_polltime');
        $data[] = $send_id;
        $data[] = $user_id;
        $data = array_merge($data,$user_group);
        $data[] = $user_id;
        $data[] = $send_id;
        $data = array_merge($data,$send_group);
        
        // 查询发送方与接收方之间的互动即时消息
        $user_bindstr = str_repeat('?,', count($user_group) - 1) . '?';
        $send_bindstr = str_repeat('?,', count($send_group) - 1) . '?';
        $rs = Db::query('select send_id,rec_id,type,content,w_time from station_news where (w_time > ?) and ( ((send_id = ?) and ((type = 1 and rec_id = ?) or (type = 2 and rec_id in (' . $user_bindstr . ')))) or ((send_id = ?) and ((type = 1 and rec_id = ?) or (type = 2 and rec_id in (' . $send_bindstr . ')))) ) order by w_time asc',$data);
        if(!$rs){
            return array('status' => false,'msg' => '没有新消息！','data' => null);
        }else{
            $startTime =  session('stationMsg_polltime');
            $endTime = end($rs)['w_time'];
            if(session('stationupdate_time') < $startTime){
                $startTime = session('stationupdate_time');
            }
            // 将时间在 $starttime,$endtime 之间对方发给自己的消息标记为已读
            $res = self::updateNoReadMsg($send_id,$user_id,$user_group,$startTime,$endTime);
            if($res['status'] !== false){
                session('stationupdate_time',$endTime);
            }
            session('stationMsg_polltime', $endTime);
            return array('status' => true,'msg' => '获取最新消息成功！','data' => $rs);
        }
    }
    
    /**
     * 截取字符函数
     * @param $str    需要截取的字符串
     * @param $start  开始位置
     * @param $len    需要截取的长度
     * @return string 截取后的字符串
     */
    public static function substrMy($str,$start=0,$len=150)
    {
        $str = strip_tags(stripslashes($str));
        $str = trim($str);
        $patternArr = array('/\s+/','/&nbsp;+/');
        $replaceArr = array(' ',' ');
        $str = preg_replace($patternArr,$replaceArr,$str);
        $str = mb_strcut($str,$start,$len,'utf-8');
        return $str;
    }
}