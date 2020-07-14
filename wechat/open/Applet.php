<?php

/**

 * 微信小程序第三方平台代处理业务

 */

namespace app\mi_openapi\home\open;



use think\facade\Cache;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;



class Applet

{

    private $thirdAppId;        //开放平台appid

    private $encodingAesKey;    //开放平台encodingAesKey

    private $thirdToken;        //开放平台token

    private $thirdAccessToken;  //开放平台access_token



    private $authorizer_appid;

    private  $authorizer_access_token;

    private  $authorizer_refresh_token;



    public function __construct($appid='',$company_token=110)

    {

        //查询第三方平台开发配置信息,需要参数appid,token,aes_key,secret

        $parentconfig = getSetting('company', 'weixinopen',null,$company_token,'');

        if(empty($parentconfig)){

            $this->errorLog("请增加微信第三方公众号平台账户信息",'');

            return error('请增加微信第三方公众号平台账户信息');

        }
        $this->thirdAppId = $parentconfig['app_id'];

        $this->encodingAesKey = $parentconfig['aes_key'];

        $this->thirdToken = $parentconfig['token'];

        $this->thirdAccessToken = $this->get_component_access_token($this->thirdAppId,$parentconfig['secret']);

        if(!empty($appid)) {

            //从数据库获取对应授权帐号参数

            $miniprogram = model('mi_openapi/wechatopenlistex')->_where([['wechat_appid', '=', $appid]])->info();

            if ($miniprogram) {

                $this->authorizer_appid = $appid;

                $miniapp = $this->update_authorizer_access_token($appid, $miniprogram['wechat_token']);

                if ($miniapp) {

                    $this->authorizer_access_token = $miniapp['authorizer_access_token'];

                    $this->authorizer_refresh_token = $miniapp['authorizer_refresh_token'];

                } else {

                    $this->errorLog("更新小程序access_token失败,appid:" . $this->authorizer_appid, '');

                    return error('更新小程序access_token失败');

                    exit;

                }

            } else {

                $this->errorLog("小程序不存在,appid:" . $this->authorizer_appid, '');

                return error('小程序不存在');

                exit;

            }

        }

    }



    public static function applet($appid='',$company_token=110){

        return new self($appid,$company_token);

    }



    /**

     * 获取第三方平台access_token(从easywechat缓存中去获取)

     * @param $appid [第三方平台appid]

     * @param $secret [第三方平台appsecret]

     * @return mixed

     */

    private function get_component_access_token($appid,$secret)

    {

        $data = json_encode([

            'component_appid'=>$appid,

            'component_appsecret'=>$secret,

            'component_verify_ticket'=>Cache::get('ComponentVerifyTicket'.$appid)

        ]);

        $key = 'easywechat.kernel.access_token.'.md5($data);

        $cache = new FilesystemAdapter('easywechat',7200);

        $numProducts  = $cache->getItem($key);

        if($numProducts->isHit()){

            return  $numProducts->get()['component_access_token'];

        }

        $url = "https://api.weixin.qq.com/cgi-bin/component/api_component_token";

        $ret = json_decode($this->https_post($url,$data),true);

        if(!isset($ret['errcode'])) {

            $numProducts->set([

                'component_access_token'=>$ret['component_access_token'],

                'expires_in'=>7200

            ]);

            $cache->save($numProducts);

            return $ret['component_access_token'];

        } else {

            return $ret['errcode'];

        }

    }







    /**

     * 重新授权

     * @param $auth_type [1表示仅仅展示公众号  2表示小程序  3全部]

     * @param $redirect_uri [回调 URI]

     * @param $biz_appid [指定授权唯一的小程序或公众号]

     * @return mixed

     */

    public function reauthorization($auth_type,$redirect_uri,$biz_appid=''){

        //获取预授权码url

        $authUrl = "https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode?component_access_token=".$this->thirdAccessToken;

        $data = json_encode(['component_appid'=>$this->thirdAppId]);



        $result = json_decode($this->https_post($authUrl,$data),true);

  

        if(!isset($result['errcode'])){

            $url = "https://mp.weixin.qq.com/cgi-bin/componentloginpage?component_appid=".$this->thirdAppId."&pre_auth_code="

                .$result['pre_auth_code']."&redirect_uri=".urlencode($redirect_uri)."&auth_type=".$auth_type."&biz_appid=".$biz_appid;

            return $url;

        }

        $this->errorLog('获取必要凭证失败',$result);

        return error("获取必要凭证失败");

    }









    /**

     *  设置小程序服务器地址，无需加https前缀，但域名必须可以通过https访问

     * @param array $domain [ 域名地址。只接收一维数组]

     * @return bool

     */

    public  function setServerDomain($domain)

    {

        $url = "https://api.weixin.qq.com/wxa/modify_domain?access_token=".$this->authorizer_access_token;

        if(is_array($domain)) {

            $https = ''; $wss = '';

            foreach ($domain as $key => $value) {

                $https .= '"https://'.$value.'",';

                $wss .= '"wss://'.$value.'",';

            }

            $https = rtrim($https,',');

            $wss = rtrim($wss,',');

            $data = '{

                "action":"add",

                "requestdomain":['.$https.'],

                "wsrequestdomain":['.$wss.'],

                "uploaddomain":['.$https.'],

                "downloaddomain":['.$https.']

            }';

        } else {

            $data = json_encode([

                "action"=>"add",

                "requestdomain"=>'https://'.$domain,

                "wsrequestdomain"=>'wss://'.$domain,

                "uploaddomain"=>'https://'.$domain,

                "downloaddomain"=>'https://'.$domain

            ]);

        }

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            return true;

        } else {

            $this->errorLog("设置小程序服务器地址失败,appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 设置小程序业务域名，无需加https前缀，但域名必须可以通过https访问

     * @param array $domain [域名地址。只接收一维数组]

     * @return bool

     */

    public function setBusinessDomain($domain)

    {

        $url = "https://api.weixin.qq.com/wxa/setwebviewdomain?access_token=".$this->authorizer_access_token;

        if(is_array($domain)) {

            $https = '';

            foreach ($domain as $key => $value) {

                $https .= '"https://'.$value.'",';

            }

            $https = rtrim($https,',');

            $data = '{

                "action":"add",

                "webviewdomain":['.$https.']

            }';

        } else {

            $data = json_encode([

                "action"=>"add",

                "webviewdomain"=>"https://".$domain

            ]);

        }

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            return true;

        } else {

            $this->errorLog("设置小程序业务域名失败,appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 成员管理，绑定小程序体验者

     * 注意：如果运营者同时也是该小程序的管理员，则无需绑定，管理员默认有体验权限。

     * @param $wechatid [体验者的微信号]

     * @return bool

     */

    public function bindMember($wechatid)

    {

        $url = "https://api.weixin.qq.com/wxa/bind_tester?access_token=".$this->authorizer_access_token;

        $data = json_encode([

            'wechatid'=>$wechatid

        ]);

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            return true;

        } else {

            $this->errorLog("绑定小程序体验者操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }











    /**

     * 成员管理，解绑定小程序体验者

     * @param $wechatid [体验者的微信号]

     * @return bool

     */

    public function unBindMember($wechatid)

    {

        $url = "https://api.weixin.qq.com/wxa/unbind_tester?access_token=".$this->authorizer_access_token;

        $data = json_encode([

            'wechatid'=>$wechatid

        ]);

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            return true;

        } else {

            $this->errorLog("解绑定小程序体验者操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 成员管理，获取小程序体验者列表

     * @return bool

     */

    public function listMember()

    {

        $url = "https://api.weixin.qq.com/wxa/memberauth?access_token=".$this->authorizer_access_token;

        $data = json_encode([

            'action'=>'get_experiencer'

        ]);

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            return $ret['members'];

        } else {

            $this->errorLog("获取小程序体验者列表操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 为授权的小程序帐号上传小程序代码

     * @param int $template_id [模板ID]

     * @param string $user_version [代码版本号]

     * @param string $user_desc [代码描述]

     * @return bool

     */

    public function uploadCode($template_id, $user_version, $user_desc)

    {

        //小程序配置文件，json格式

        $ext_json = json_encode([

            'extEnable'=>true,

            'extAppid'=>$this->authorizer_appid, //授权给第三方平台的小程序

//            'ext'=>[

//                'name'=>'',

//                "attr"=>[

//                    "host"=>"open.weixin.qq.com",

//                    "users"=>[

//                        "user_1",

//                        "user_2"

//                    ]

//                ]

//            ]

        ]);

        $url = "https://api.weixin.qq.com/wxa/commit?access_token=".$this->authorizer_access_token;

        $data = json_encode([

            'template_id'=>$template_id,

            'ext_json'=>$ext_json,

            'user_version'=>$user_version,

            'user_desc'=>$user_desc

        ],JSON_UNESCAPED_UNICODE);

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] != 0) {

            $this->errorLog("为授权的小程序帐号上传小程序代码操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        } else {

            $this->updateAppletStatus(1,$this->authorizer_appid);

            return true;

        }

    }



    /**

     * 获取体验小程序的体验二维码

     * @param string $path [指定体验版二维码跳转到某个具体页面]

     * @return bool|string

     */

    public function getExpVersion($path='')

    {

        $url = "https://api.weixin.qq.com/wxa/get_qrcode?access_token=".$this->authorizer_access_token;

        if($path){

            $url = "https://api.weixin.qq.com/wxa/get_qrcode?access_token=".$this->authorizer_access_token."&path=".urlencode($path);

        }

        $ret = json_decode($this->https_get($url),true);

        if(isset($ret['errcode'])) {

            $this->errorLog("获取体验小程序的体验二维码操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        } else {

            return $url;

        }

    }



    /**

     * 获取草稿列表

     */

    public function getDraftList(){

        $url = "https://api.weixin.qq.com/wxa/gettemplatedraftlist?access_token=".$this->thirdAccessToken;

        $ret = json_decode($this->https_get($url),true);

        if($ret['errcode'] != 0) {

            $this->errorLog("获取小程序草稿列表操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        } else {

            return $ret;

        }

    }



    /**

     * 将草稿列表上传到模版列表

     * @param $draftId [草稿id]

     * @return mixed

     */

    public function addDraftToTemplate($draftId){

        $url = "https://api.weixin.qq.com/wxa/addtotemplate?access_token=".$this->thirdAccessToken;

        $data = json_encode([

            'draft_id'=>$draftId,

        ]);

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == 0) {

            return true;

        } else {

            $this->errorLog("将草稿列表上传到模版列表操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 获取小程序模版列表

     * @return bool|string

     */

    public function getTemplateList(){

        $url = "https://api.weixin.qq.com/wxa/gettemplatelist?access_token=".$this->thirdAccessToken;

        $ret = json_decode($this->https_get($url),true);

        if($ret['errcode'] != 0) {

            $this->errorLog("获取小程序模版列表操作失败,appid:".$this->authorizer_appid,$ret);

            return false;

        } else {

            return $ret;

        }

    }







    /**

     * 提交审核

     * @param string $tag [小程序标签，多个标签以空格分开]

     * @param string $title [小程序页面标题，长度不超过32]

     * @return bool

     */

    public function submitReview($tag,$title)

    {
        $first_class = '';

        $second_class = '';

        $first_id = 0;

        $second_id = 0;

        $address = "pages/index/index";

        $category = $this->getCategory();

        if(!empty($category)) {

            $first_class = $category[0]['first_class'] ?? '' ;

            $second_class = $category[0]['second_class'] ?? '';

            $first_id = $category[0]['first_id'] ?? 0;

            $second_id = $category[0]['second_id'] ?? 0;

        }

        $getpage = $this->getPage();

        if(!empty($getpage) && isset($getpage[0])) {

            $address = $getpage[0];

        }

        $url = "https://api.weixin.qq.com/wxa/submit_audit?access_token=".$this->authorizer_access_token;

        $data = '{

                "item_list":[{

                    "address":"'.$address.'",

                    "tag":"'.$tag.'",

                    "title":"'.$title.'",

                    "first_class":"'.$first_class.'",

                    "second_class":"'.$second_class.'",

                    "first_id":"'.$first_id.'",

                    "second_id":"'.$second_id.'"

                }]

            }';

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            $insertData = [

                'appid'=>$this->authorizer_appid,

                'auditid'=>$ret['auditid'],

                'create_time'=>time()

            ];

           $res =  model('mi_openapi/Wxminiprogramex')->addSave($insertData);
           C();
            if($res){
                $this->updateAppletStatus(2,$this->authorizer_appid);
            }
            return true;

        } else {

            $this->errorLog("小程序提交审核操作失败，appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 小程序审核撤回

     * 单个帐号每天审核撤回次数最多不超过1次，一个月不超过10次。

     * @return bool

     */

    public function unDoCodeAudit()

    {

        $url = "https://api.weixin.qq.com/wxa/undocodeaudit?access_token=".$this->authorizer_access_token;

        $ret = json_decode($this->https_get($url),true);

        if($ret['errcode'] == '0') {

            return true;

        } else {

            $this->errorLog("小程序审核撤回操作失败，appid:".$this->authorizer_appid,$ret);

            if($ret['errcode'] == 87013){

                return error("撤回次数达到上限");

            }elseif($ret['errcode'] == -1){

                return error("微信系统错误,请稍后再试");

            }else{

                return error($ret['errmsg']);

            }

        }

    }





    /**

     * 查询指定版本的审核状态

     * @param $auditid [提交审核时获得的审核id]

     * @return bool

     */

    public function getAuditStatus($auditid)

    {

        $url = "https://api.weixin.qq.com/wxa/get_auditstatus?access_token=".$this->authorizer_access_token;

        $data = json_encode([

            'auditid'=>$auditid

        ]);

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            $reason = $ret['reason'] ?? '';

            $updateData = [

                'status'=>$ret['status'],

                'reason'=>$reason

            ];

            model('mi_openapi/Wxminiprogramex')->addSave($updateData,[['auditid','=',$auditid]]);

            return true;

        } else {

            $this->errorLog("查询指定版本的审核状态操作失败，appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 查询最新一次提交的审核状态

     * @return bool

     */

    public function getLastAudit()

    {

        $url = "https://api.weixin.qq.com/wxa/get_latest_auditstatus?access_token=".$this->authorizer_access_token;

        $ret = json_decode($this->https_get($url),true);

        if($ret['errcode'] == '0') {

            return $ret['status']; //审核状态[0审核成功,1审核被拒,2审核中,3已撤回]

        } else {

            $this->errorLog("查询最新一次提交的审核状态操作失败，appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 发布已通过审核的小程序

     * @return bool

     */

    public function release()

    {

        $url = "https://api.weixin.qq.com/wxa/release?access_token=".$this->authorizer_access_token;

        $data = '{}';

        $ret = json_decode($this->https_post($url,$data),true);

        if($ret['errcode'] == '0') {

            $this->updateAppletStatus(3,$this->authorizer_appid);

            return true;

        } else {

            $this->errorLog("发布已通过审核的小程序操作失败，appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 获取授权小程序帐号的可选类目

     * @return bool

     */

    private function getCategory()

    {

        $url = "https://api.weixin.qq.com/wxa/get_category?access_token=".$this->authorizer_access_token;

        $ret = json_decode($this->https_get($url),true);

        if($ret['errcode'] == '0') {

            return $ret['category_list'];

        } else {

            $this->errorLog("获取授权小程序帐号的可选类目操作失败，appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /**

     * 小程序微信登录

     * @param $appid [小程序的 AppID]

     * @param $code [js_code]

     * @return bool

     */

    public function appletLogin($appid,$code){

        $cacheResult = Cache::get('applet'.$code);

        if(!empty($cacheResult)){

            return json_decode($cacheResult,true);

        }

        $component_appid = $this->thirdAppId;//第三方平台 appid

        $component_access_token = $this->thirdAccessToken;//令牌

        $url = 'https://api.weixin.qq.com/sns/component/jscode2session?appid='.$appid.'&js_code='.$code.'&grant_type=authorization_code&component_appid='.$component_appid.'&component_access_token='.$component_access_token;

        $ret = json_decode($this->https_get($url),true);

        if(!isset($ret['errcode'])) {

            //缓存结果五分钟

            Cache::set('applet'.$code,json_encode($ret),18000);

            return $ret;

        } else {

            $this->errorLog("小程序登录失败，appid:".$appid,$ret);

            return false;

        }

    }



    /**

     * 获取小程序的第三方提交代码的页面配置

     * @return bool

     */

    public function getPage()

    {

        $url = "https://api.weixin.qq.com/wxa/get_page?access_token=".$this->authorizer_access_token;

        $ret = json_decode($this->https_get($url),true);

        if($ret['errcode'] == 0) {

            return $ret['page_list'];

        } else {

            $this->errorLog("获取小程序的第三方提交代码的页面配置失败，appid:".$this->authorizer_appid,$ret);

            return false;

        }

    }



    /*************************************************小程序发送订阅消息相关接口**************************************************/



    /**

     * 获取当前帐号所设置的类目信息

     * @return bool | array

     */

    public function getNewCategory(){

        $url = 'https://api.weixin.qq.com/wxaapi/newtmpl/getcategory?access_token='.$this->authorizer_access_token;

        $res = json_decode($this->https_get($url),true);

        if($res['errcode'] == '0'){

            return $res['data'];

        }

        $this->errorLog('获取当前帐号所设置的类目信息失败'.$this->authorizer_appid,$res);

        return false;

    }



    /**

     * 获取模板标题列表

     * @param $start [用于分页，表示从 start 开始。从 0 开始计数。]

     * @param $limit [用于分页，表示拉取 limit 条记录。最大为 30]

     * @return bool | array

     */

    public function getPubTemplateTitles($start=0,$limit=30){

        $idArr = $this->getNewCategory();

        if(!$idArr || empty($idArr)){

            return error("暂无类目信息");

        }

        $ids = implode(',',array_column($idArr,'id'));

        $url = 'https://api.weixin.qq.com/wxaapi/newtmpl/getpubtemplatetitles?access_token='.$this->authorizer_access_token.

            '&ids='.$ids.'&start='.$start.'&limit='.$limit;

        $res = json_decode($this->https_get($url),true);

        if($res['errcode'] == '0'){

            return ['total'=>$res['count'],'data'=>$res['data']];

        }

        $this->errorLog('获取模板标题列表失败'.$this->authorizer_appid,$res);

        return false;

    }



    /**

     * 获取模板标题下的关键词库

     * @param $tid [从获取模板标题列表API（getPubTemplateTitles）获取]

     * @return mixed

     */

    public function getPubTemplateKeywords($tid){

        $url = 'https://api.weixin.qq.com/wxaapi/newtmpl/getpubtemplatekeywords?access_token='.$this->authorizer_access_token.

            '&tid='.$tid;

        $res = json_decode($this->https_get($url),true);

        if($res['errcode'] == '0'){

            return $res['data'];

        }

        $this->errorLog('获取模板标题下的关键词库失败'.$this->authorizer_appid,$res);

        return false;

    }





    /**

     * 组合模板并添加到个人模板库

     * @param string $tid [从获取模板标题列表API（getPubTemplateTitles）获取]

     * @param array $kidList [开发者自行组合好的模板关键词列表，关键词顺序可以自由搭配（例如 [3,5,4] 或 [4,5,3]），最多支持5个，最少2个关键词组合]

     * @param string $sceneDesc [服务场景描述，15个字以内]

     * @return bool

     */

    public function addTemplate(string $tid,array $kidList,string $sceneDesc){

        $url = 'https://api.weixin.qq.com/wxaapi/newtmpl/addtemplate?access_token='.$this->authorizer_access_token;

        $sendData = json_encode([

            'tid'=>$tid,

            'kidList'=>$kidList,

            'sceneDesc'=>$sceneDesc

        ],JSON_UNESCAPED_UNICODE);



        $res = json_decode($this->https_post($url,$sendData,true),true);


        if($res['errcode'] == '0'){

            return true;

        }

        $this->errorLog('组合模板并添加到个人模板库失败'.$this->authorizer_appid,$res);

        return false;

    }



    /**

     * 获取帐号下的模板列表

     * @return array | bool

     */

    public function getTemplate(){

        $url = 'https://api.weixin.qq.com/wxaapi/newtmpl/gettemplate?access_token='.$this->authorizer_access_token;

        $res = json_decode($this->https_get($url),true);

        if($res['errcode'] == '0'){

            return $res['data'];

        }

        $this->errorLog('获取帐号下的模板列表失败'.$this->authorizer_appid,$res);

        return false;

    }



    /**

     * 删除帐号下的某个模板

     * @param string $priTmplTitle [要删除的模板标题]

     * @return bool

     */

    public function delTemplate(string $priTmplTitle){

        $res = $this->getTemplate();

        if(!$res){

            return false;

        }

        foreach ($res as $val){

            if($val['title'] == $priTmplTitle){

                $url = 'https://api.weixin.qq.com/wxaapi/newtmpl/deltemplate?access_token='.$this->authorizer_access_token;

                $sendData = json_encode([

                    'priTmplId'=>$val['priTmplId']

                ]);

                $res = json_decode($this->https_post($url,$sendData,true),true);

                if($res['errcode'] == '0'){

                    return true;

                }

                $this->errorLog('删除帐号下的某个模板失败'.$this->authorizer_appid,$res);

                return false;

            }

        }

    }



    /**

     * 小程序发送消息给客户端

     * @param string $touser [接收者（用户）的 openid]

     * @param string $template_title [所需下发的模板消息的标题]

     * @param array $data [模板内容，不填则下发空模板 具体格式请参考对应模版格式]
     *
     * @param string $jump_url [用户点击推送消息时跳转地址]

     * @return bool

     */

    public function sendMsgToClient(string $touser,string $template_title,array $data,string $jump_url=''){



        $temp_info = $this->accordingTitleGetTemplateId($template_title);



        if(!$temp_info){

            return false;

        }

        $url = 'https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token='.$this->authorizer_access_token;

        $sendData = json_encode([

            'touser'=>$touser,

            'template_id'=>$temp_info['temp_id'],

            'page' => $jump_url,

            'data' => $data,

        ]);



        $res = json_decode($this->https_post($url,$sendData),true);

    

        if($res['errcode'] == '0'){

            return true;

        }

        $this->errorLog('小程序发送消息给客户端失败'.$this->authorizer_appid,$res);

        return false;

    }



    /****************************************** 公众号网页授权 ************************************/



    /**

     * 公众号网页授权

     * @param $code

     * @return mixed

     */

    public function getUserInfo($code){

        if (!session('wechatInfo')) {

            $url = 'https://api.weixin.qq.com/sns/oauth2/component/access_token?appid=' . $this->authorizer_appid . '&code=' . $code .

                '&grant_type=authorization_code&component_appid=' . $this->thirdAppId . '&component_access_token=' . $this->thirdAccessToken;

            $this->errorLog('请求url'.$this->authorizer_appid,$url);

            $data = json_decode($this->https_get($url), true);

            $this->errorLog('凭证信息'.$this->authorizer_appid,$data);

            if(isset($data['errcode'])){

                return error('code 失效');

            }

            $getUserInfoUrl = 'https://api.weixin.qq.com/sns/userinfo?access_token=' . $data['access_token'] . '&openid=' . $data['openid'] . '&lang=zh_CN';

            $userinfo = json_decode($this->https_get($getUserInfoUrl), true);

            $this->errorLog('用户信息'.$this->authorizer_appid,$userinfo);

            session('wechatInfo', $userinfo);

        }else{

            $userinfo=session('wechatInfo');

        }

        return $userinfo;

    }





    /**

     * 根据模版标题获取模版id

     * @param $templateTitle

     * @return array | bool

     */

    private function accordingTitleGetTemplateId($templateTitle){

        $data = $this->getTemplate();

        if(empty($data)){

            return false;

        }

        foreach ($data as $val){

            if($val['title'] == $templateTitle){

                return [
                    'temp_id'=>$val['priTmplId'],
                    'content'=>$val['content']    
                ];

            }

        }

    }







    /**

     * 更新授权小程序的authorizer_access_token

     * @param $appid [小程序appid]

     * @param $refresh_token [小程序authorizer_refresh_token]

     * @return mixed|null

     */

    private function update_authorizer_access_token($appid,$refresh_token)

    {

        $url = 'https://api.weixin.qq.com/cgi-bin/component/api_authorizer_token?component_access_token=' . $this->thirdAccessToken;

        $data = json_encode([

            'component_appid'=>$this->thirdAppId,

            'authorizer_appid'=>$appid,

            'authorizer_refresh_token'=>$refresh_token

        ]);

        $ret = json_decode($this->https_post($url, $data),true);

        if (isset($ret['authorizer_access_token'])) {

            //需要更新的数据

            $update_data = [

                'wechat_token' => $ret['authorizer_refresh_token']

            ];

            model('mi_openapi/wechatopenlistex')->addSave($update_data,[['wechat_appid','=',$appid]]);

            return $ret;

        } else {

            $this->errorLog("更新授权小程序的authorizer_access_token操作失败,appid:".$appid,$ret);

            return null;

        }

    }



    /**

     * 记录错误信息方便后期排查问题

     * @param $msg

     * @param $ret

     */

    private function errorLog($msg,$ret)

    {

        file_put_contents(__DIR__ . '/error/applet.log', "[" . date('Y-m-d H:i:s') . "] ".$msg."," .json_encode($ret).PHP_EOL, FILE_APPEND);

    }



    /**

     * 修改对应小程序的操作状态

     * @param $status ['0'=>'未做任何操作','1'=>'已上传代码','2'=>'已提交审核','3'=>'已发布小程序']

     * @param $appid [对应小程序appid]

     */

    private function updateAppletStatus($status,$appid){

        //修改状态

        model('mi_openapi/Wechatopenlistex')->addSave(['operation_status'=>$status],[['wechat_appid','=',$appid]]);

        C();

    }


    /**

     * 把模版内容转换成对应的微信发送通知内容格式

     * @param $templateContent
     
     * @param $data

     * @return array

     */

    private function templateContentToData($templateContent,$data){

        $pattern = '/{{(.*)\./u';

        preg_match_all($pattern, $templateContent, $match);

        $arr = array_count_values($match[1]);

        $newArr = [];

        $start = 1;

        foreach ($arr as $key => $value) {

            $newArr[$key] = $data[$start];

            $start++; 

        }

        return $newArr;

    }



    /*

   * 发起POST网络提交

   * @params string $url : 网络地址

   * @params json $data ： 发送的json格式数据

   */

    private function https_post($url,$data,$is_header=false)

    {

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);

        if($is_header) {

            $header  = [

                'Content-Type:application/json; charset=UTF-8'

            ];

            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

        }

        if (!empty($data)){

            curl_setopt($curl, CURLOPT_POST, 1);

            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $output = curl_exec($curl);

        curl_close($curl);

        return $output;

    }

    /*

   * 发起GET网络提交

   * @params string $url : 网络地址

   */

    private function https_get($url)

    {

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);

        curl_setopt($curl, CURLOPT_HEADER, FALSE) ;

        curl_setopt($curl, CURLOPT_TIMEOUT,60);

        if (curl_errno($curl)) {

            return 'Errno'.curl_error($curl);

        }

        else{$result=curl_exec($curl);}

        curl_close($curl);

        return $result;

    }

}