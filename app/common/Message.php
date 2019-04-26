<?php
namespace app\common;

use app\common\Clientstate;
use app\common\Connection;
use app\common\Srp6;
use app\common\WebSocket;
use Thinbus\ThinbusSrp;
class Message
{
    /**
     * websocket握手和消息分发
     *
     * @param swoole_server $serv
     * @param int $fd
     * @param string $data
     */
    public function send($serv, $fd, $data)
    {
        if (!empty($data)) {
            $connectionCls = new Connection();

            // websocket握手，如果是握手则直接返回
            if ($this->wsHandShake($serv, $fd, $data)) {
                echolog("websocket handsake.");
                $connectionCls->saveConnector($fd, Clientstate::CONNECTION_TYPE_WEBSOCKET);
                return;
            }

            // 判断客户端类型，对websocket的消息进行解包
            $connectionType = $connectionCls->getConnectionType($fd);
            if ($connectionType == Clientstate::CONNECTION_TYPE_WEBSOCKET) {
                echolog("I am websocket.");
                $ws   = new WebSocket();
                $data = $ws->unwrap($data);
            }

            // 验证逻辑
            $state = $connectionCls->getConnectorUserId($fd);
            $this->handlePacket($serv, $fd, $data, $state);

            /*貌似客户端是按长度来约定结束的, 不需要解包拼包*/
            // 数据拆包
            // $messageArr = (new MessageCache())->getSplitDataList($fd, $data);

            // // 如果没有完整的消息，则直接返回，直到收到完整消息再处理
            // echolog($messageArr);
            // if (empty($messageArr) && !is_array($messageArr)) {
            //     return;
            // }

            // // 将所有收到的所有完整消息进行投递处理
            // for ($i = 0; $i < count($messageArr); $i++) {
            //     $this->sendMessage($serv, $fd, $messageArr[$i], $connectionType);
            // }
        }
    }

    // 转成byte数组
    public function getBytes($string)
    {
        $bytes = array();
        for ($i = 0; $i < strlen($string); $i++) {
            //遍历每一个字符 用ord函数把它们拼接成一个php数组
            $bytes[] = ord($string[$i]);
        }
        return $bytes;
    }

    // 转成数据包字符串
    public function toStr($bytes)
    {
        $str = '';
        foreach ($bytes as $ch) {
            $str .= chr($ch); //这里用chr函数
        }
        return $str;
    }

    /**
     * [getinfo_ClientLogonChallenge 解包数据]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-04-19
     * ------------------------------------------------------------------------------
     * @return  [type]          [description]
     */
    public function getinfo_ClientLogonChallenge($data)
    {
        $newdata = $data;
        echolog($data);
        $data = $this->getBytes($data);

        $info    = [];
        $info['cmd']           = $data[0]; //命令
        $info['error']         = $data[1]; //错误
        $info['size']          = array_slice($data, 2, 2);
        $info['gamename']      = strrev($this->toStr(array_slice($data, 4, 3)));
        $info['version']       = $data[8] . '.' . $data[9] . '.' . $data[10];
        $info['build']         = array_slice($data, 11, 1);
        $info['platform']      = strrev($this->toStr(array_slice($data, 13, 3)));
        $info['os']            = strrev($this->toStr(array_slice($data, 17, 3)));
        $info['country']       = strrev($this->toStr(array_slice($data, 21, 4)));
        $info['timezone_bias'] = array_slice($data, 25, 4);
        $info['ip']            = implode('.', array_slice($data, 29, 4));

        $info['user_lenth'] = $data[33]; //用户名长度
        $info['username']   = array_slice($data, 34, $info['user_lenth']); //截取用户名
        $info['username']   = $this->toStr($info['username']);
        echolog($info);

        return $info;
    }

    /**
     * AuthServerLogonChallenge 验证数据]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-04-19
     * ------------------------------------------------------------------------------
     * @return  [type]          [description]
     */
    public function AuthServerLogonChallenge($data,$fd)
    {
        $connectionCls = new Connection();
        $username = $connectionCls->getConnectorUsername($fd);
        // 借助python实现srp6验证(字符类型)
        $output = @shell_exec('python core.py 1 '.$username. ' ' . base64_encode($data));
        $data   = trim($output);
        $data   = substr($data, 1, -1);
        $data   = str_replace(' ', '', $data);
        $data   = explode(',', $data);
        foreach ($data as $k => $v) 
        {
            $data[$k] = (int)$v;
        }
        echolog($this->toStr($data));
        return $data;
    }
    
    /**
     * [getAuthSrp 获取Srp]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-04-20
     * ------------------------------------------------------------------------------
     * @return  [type]          [description]
     */
    public function getAuthSrp($data = null)
    {
        // // 借助python实现srp6验证(betys)
        // $output = @shell_exec('python core.py 0 '.$data['username']);
        // $array = explode(',', $output);
        // $data = $array[0];
        // return $data;

        // 借助python实现srp6验证(字符类型)
        $output = @shell_exec('python core.py 0 ' . $data['username']);
        $data   = trim($output);
        $data   = substr($data, 1, -1);
        $data   = explode(',', $data);
        $data   = $this->toStr($data);
        echolog($data);
        return $data;


        // // //这块是php写法 暂时没有调用
        // $N = base_convert("0x894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7", 16, 10);
        // // $N = int_helper::uInt32("0x894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7");

        // $g   = 7;
        // $k   = 3;
        // $H   = 'sha256';
        // $srp = new ThinbusSrp($N, $g, $k, $H);
        // $B   = $srp->step1($data['username'], $data['username'], $data['username']);
        // $cmd      = 0x00;
        // $err      = 0x00;
        // $unk      = 0;
        // $B        = $this->getBytes($B);
        // $g_len    = 1;
        // $g        = $g;
        // $n_len    = 32;
        // $n        = $this->getBytes($N);
        // $srp_salt = $data['username'];
        // $crc_salt = $data['username'];

        // $data = [];
        // $data[] = $cmd;
        // $data[] = $err;
        // $data[] = $unk;var_dump($B);
        // foreach ($B as $k => $v) 
        // {
        //     $data[] = $v;
        // }
        // $data[] = $g;
        // $data[] = $n_len;
        // foreach ($n as $k => $v) 
        // {
        //     $data[] = $v;
        // }
        // $data[] = $n_len;
        // echolog($data);

        // $packdata = pack(
        //     'N*',
        //     $cmd,
        //     $err,
        //     $unk,
        //     $B,
        //     $g_len,
        //     $g,
        //     $n_len,
        //     $n,
        //     $srp_salt,
        //     $crc_salt
        // );

        // // $data = $this->toStr($packdata);
        // echolog($packdata);
        // return $packdata;
    }

    /**
     * [getRealmInfo 获取服务器列表]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-04-27
     * ------------------------------------------------------------------------------
     * @return  [type]          [description]
     */
    public function getRealmInfo()
    {
        // 模拟数据
        $name = 'WpcoreServer'; //服务器名称
        $addr_port = '127.0.0.1:13250'; //服务器端口
        $population = 'zhCN'; //服务器本地化

        $output = @shell_exec('python core.py 2'.' '.$name.' '.$addr_port.' '.$population);
        echolog($output);
        $data   = trim($output);
        $data   = substr($data, 1, -1);
        $data   = str_replace(' ', '', $data);
        $data   = explode(',', $data);
        return $data;
    }

    /**
     * [handlePacket 根据当前ClientState处理传入的数据包]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-04-19
     * ------------------------------------------------------------------------------
     * @return  [type]          [description]
     */
    public function handlePacket($serv, $fd, $data, $state)
    {
        $Connection = new Connection();

        switch ($state) {
            case Clientstate::Init:
                // 第一步srp6运算
                // file_put_contents('auth_'.time() . '_ClientLogonChallenge.log', $data . PHP_EOL, FILE_APPEND);
                $data = $this->getinfo_ClientLogonChallenge($data); //解析数据包

                //ToDo
                /*
                1) 检查该ip是否被封禁，假如是发送相应的错误

            　　2) 查询数据库中是否有该账户，假如没有返回相应的错误

            　　3) 查看最后一次登录ip与账户是否绑定，假如绑定对比当前ip与last_ip是否一致

            　　4) 检查该账号是否被封禁，假如是发送相应的错误信息

            　　5) 获取用户名密码，开始SRP6计算(已模拟完成)

                6) _accountSecurityLevel，保存用户的权限等级，普通用户、GM、admin等等

                7) 本地化：根据_localizationName的名字找对应的.mpq文件所在的位置比如enUS，zhTW，zhCN
                */

                $Connection->saveConnector($fd, 0, Clientstate::ClientLogonChallenge,$data['username']); //初始化auth状态 1
                $serv->send($fd, $this->getAuthSrp($data));
                break;

            case Clientstate::ClientLogonChallenge:
                // 第二步srp6校验
                // file_put_contents('auth_'.time() . '_ServerLogonChallenge.log', $data . PHP_EOL, FILE_APPEND);
                
                $data = $this->AuthServerLogonChallenge($data,$fd); //验证

                $serv->send($fd,$this->toStr($data));

                if(count($data) != 3)
                {
                    $Connection->saveConnector($fd, 0, Clientstate::Authenticated); //初始化auth状态 5
                    echolog('Password verification succeeded');
                }else{
                    $Connection->saveConnector($fd, 0, Clientstate::Init); //初始化auth状态 0
                    $serv->close($key);//关闭连接
                    echolog('Password verification failed');
                }

                break;

            case Clientstate::Authenticated:
                // 第三步获取服务器列表
                // file_put_contents('auth_'.time() . '_Authenticated.log', $data . PHP_EOL, FILE_APPEND);
                echolog($data);
                echolog(5);
                $data = $this->getRealmInfo();

                // //分批发包
                $RealmHeader_Server = array_slice($data, 0,8);
                $RealmInfo_Server = array_slice($data, 8,19);
                $RealmFooter_Server = array_slice($data, 19,2);
                $serv->send($fd,$this->toStr($data));
                $serv->send($fd,$this->toStr($RealmInfo_Server));
                $serv->send($fd,$this->toStr($RealmFooter_Server));

                // 一次性发包
                // $serv->send($fd,$this->toStr($data));
                break;
        }
    }

    /**
     * 进行身份验证 根据消息类型进行不同的处理
     *
     * @param swoole_server $serv
     * @param int $fd
     * @param string $data
     * @param int $connectionType
     */
    private function sendMessage($serv, $fd, $data, $connectionType)
    {
        $msgArr = json_decode($data, true);

        $serv->send($fd, $this->getResultJson("N00000", "身份校验成功", "123"));
    }

    /**
     * websocket握手
     *
     * @param swoole_server $serv
     * @param int $fd
     * @param string $data
     * @return boolean 如果为websocket连接则进行握手，握手成功返回true，否则返回false
     */
    private function wsHandShake($serv, $fd, $data)
    {
        // 判断客户端类型 通过websocket握手时的关键词进行判断
        if (strpos($data, "Sec-WebSocket-Key") > 0) {
            $ws            = new WebSocket();
            $handShakeData = $ws->getHandShakeHeaders($data);
            $serv->send($fd, $handShakeData);
            return true;
        }
        return false;
    }

    /**
     * 生成发送的json数据
     *
     * @param string $code
     *            状态码
     * @param string $message
     *            消息结果提示
     * @param array $data
     *            发送的数据
     * @param int $infoType
     *            消息类型
     * @param int $connectionType
     *            连接类型
     * @return string
     */
    private function getResultJson($code, $message, $data, $infoType = 0, $connectionType = Clientstate::CONNECTION_TYPE_SOCKET)
    {
        if (empty($message)) {
            return $message;
        }

        $apiResult = array(
            "code"     => $code,
            "message"  => $message,
            "infoType" => $infoType,
            "data"     => $data,
        );

        $result = json_encode($apiResult, JSON_UNESCAPED_UNICODE) . Clientstate::MESSAGE_END_FLAG;

        if ($connectionType == Clientstate::CONNECTION_TYPE_WEBSOCKET) {
            $ws     = new WebSocket();
            $result = $ws->wrap($result);
        }

        return $result;
    }
}