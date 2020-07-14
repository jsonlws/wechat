<?php

/**

 * 微信公众号第三方平台代处理业务

 */

namespace app\mi_openapi\home\open;



use EasyWeChat\Factory;

use think\facade\Cache;



class Official

{

    protected $officialAccount;

    protected $appId;

    protected $userAppid;



    /**

     * main constructor.

     * @param $appId [授权appid]

     * @param $company_token [公司标识]

     */

    public function __construct($appId,$company_token=110){

        $this->userAppid = $appId;

        $this->init($appId,$company_token);

    }



    /**

     * 静态调用

     * @param $appId [授权appid]

     * @param $company_token [公司标识]

     * @return official

     */

    public static function official($appId,$company_token=110){

        return  new self($appId,$company_token);

    }



    /**

     * 开放平台必要参数

     *  @param $company_token [公司标识]

     * @return array

     */

    private function openConfig($company_token=110){

        $parentconfig = getSetting('company', 'weixinopen',null,$company_token,'');

        return [

            'app_id'   => $parentconfig['app_id'],//$items['app_id'], //开放平台第三方平台 APPID

            'secret'   => $parentconfig['secret'],//$items['secret'], //开放平台第三方平台 Secret

            'token'    => $parentconfig['token'],//$items['token'], //开放平台第三方平台 Token

            'aes_key'  => $parentconfig['aes_key'],//$items['aes_key']  //开放平台第三方平台 AES Key

            "oauth"  => [

                'scopes'   => ['snsapi_userinfo'],

                'callback' => url('lp_wechat/Index/auth'),

            ]

        ];

    }





    /**

     * 初始化方法

     * @param $appId [授权公众号appid]

     * @param int $company_token

     * @return mixed

     */

    private function init($appId,$company_token=110){

        if(empty($appId)){

            return error("缺少授权公众号appid");

        }

        $config = $this->openConfig($company_token);

        $this->appId = $config['app_id'];

        $openPlatform = Factory::openPlatform($config);

        $data = model("mi_openapi/wechatopenlistex")->_where([['wechat_appid','=',$appId]])->info();

        $this->officialAccount = $openPlatform->officialAccount($appId, $data['wechat_token']);

    }



    /**

     * 记录错误信息方便后期排查问题

     * @param $msg

     * @param $ret

     */

    private function errorLog($msg,$ret)

    {

        file_put_contents(__DIR__ . '/error/official.log', "[" . date('Y-m-d H:i:s') . "] ".$msg."," .json_encode($ret).PHP_EOL, FILE_APPEND);

    }





    /**

     * 获取已设置菜单

     * @return array

     */

    public function getSetMenu(){

        $res = $this->officialAccount->menu->list();

        if(!isset($res['errcode'])){

            return $res;

        }else{

            return error($res['errmsg']);

        }

    }



    /**

     * 获取当前按钮

     */

    public function getCurrentMenu(){

        return $this->officialAccount->menu->current();

    }





    /**

     * 删除菜单(默认全部删除)

     * @param string $menuId [菜单id 传入则删除指定按钮]

     * @return mixed

     */

    public function delList($menuId=null){

        return $this->officialAccount->menu->delete($menuId);

    }



    /**

     * 添加菜单按钮

     * @param array $buttons [按钮设置]

     * @param array $matchRule [个性化按钮设置可以不传]

     * @return mixed

     */

    public function addMenu(array $buttons,array $matchRule=[]){

        $res = $this->officialAccount->menu->create($buttons,$matchRule);

        if($res['errcode'] == '0'){

            return true;

        }else{

            return $res['errmsg'];

        }



    }



    /**

     * 群发消息操作

     * @param $type [对应操作指令]

     * @param array $params [操作对应指令所需要的参数]

     * @return string

     */

    public function massHair($type,$params=[]){

        switch ($type){

            //文本消息

            case "text":

                $content = $params['content'];

                $res = $this->officialAccount->broadcasting->sendText($content);

                break;

            //图文消息

            case "news":

                $mediaId = $params['mediaId'];

                $res = $this->officialAccount->broadcasting->sendNews($mediaId);

                break;

            //图片消息

            case "image":

                $mediaId = $params['mediaId'];

                $res = $this->officialAccount->broadcasting->sendImage($mediaId);

                break;

            //语音消息

            case "voice":

                $mediaId = $params['mediaId'];

                $res = $this->officialAccount->broadcasting->sendVoice($mediaId);

                break;

            //卡券消息

            case "card":

                $cardId = $params['cardId'];

                $res = $this->officialAccount->broadcasting->sendCard($cardId);

                break;

            //视频消息

            case "video":

                $videoId = $params['videoId'];

                $res = $this->officialAccount->broadcasting->sendVideo($videoId);

                break;

        }

        return $res;

    }



    /**

     * 上传视频素材供发送视频消息使用

     * @param $video

     * @param $videoTitle [视频标题]

     * @param $videoDes [视频描述]

     * @return mixed

     */

    public function uploadVideo($video,$videoTitle,$videoDes){

        $res = $this->officialAccount->material->uploadVideo($video, $videoTitle, $videoDes);

        return $res['media_id'];

    }

    /**
     * 上传图片素材
     * @param $path
     * @return mixed
     */
    public function uploadImage($path){
        return $this->officialAccount->material->uploadImage($path);

    }

    /**
     * 上传语音素材
     * @param $path
     * @return mixed
     */
    public function uploadVoice($path){
       return $this->officialAccount->material->uploadVoice($path);
    }




    /**

     * 消息回复

     * @param $msgId [消息id]

     * @param $replyContent [回复消息内容]

     * @return mixed

     */

    public function msgReply($replyContent,$msgId){

        if(empty($replyContent) || empty($msgId)){

            return error("缺少回复内容/消息id");

        }

        $data = model("mi_openapi/Usermsgex")->_where([['id','=',$msgId]])->info();

        return $this->officialAccount->customer_service->message($replyContent)->to($data['from_openid'])->send();

    }



    /**

     * 获取支持的行业列表

     * @return mixed

     */

    public function getIndustry(){

        return $this->officialAccount->template_message->getIndustry();

    }



    /**

     * 添加模版消息

     * @param $shortId

     * @return bool

     */

    public function addTemplate($shortId){

        $res = $this->officialAccount->template_message->addTemplate($shortId);

        if($res['errcode'] == '0'){

            return true;

        }

        $this->errorLog('添加模版操作失败'.$this->userAppid,json_encode($res));

        return false;

    }



    /**

     * 删除模版

     * @param $templateTitle [模版名称唯一标识]

     * @return mixed

     */

    public function delTemplate($templateTitle){

        $temp_info = $this->accordingTitleGetTemplateId($templateTitle);

        if(!$temp_info){

            return false;

        }

        $res  = $this->officialAccount->template_message->deletePrivateTemplate($temp_info['template_id']);

        if($res['errcode'] == '0'){

            return true;

        }

        $this->errorLog('删除模版操作失败'.$this->userAppid,json_encode($res));

        return false;

    }





    /**

     * 获取模版列表

     */

    public function getTemplateList(){

        $res = $this->officialAccount->template_message->getPrivateTemplates();
        if(isset($res['errcode'])){
            return [];
        }
        return $res;

    }



    /**

     * 发送模版消息内容

     * @param $openid

     * @param $title

     * @param array $templateData

     * @return mixed

     */

    public function sendTemplateContent($openid,$title,array $templateData){

        $temp_info = $this->accordingTitleGetTemplateId($title);

        if(!$temp_info){

            return false;

        }

        return $this->officialAccount->template_message->send([

            'touser' => $openid,

            'template_id' => $temp_info['template_id'],

            'data' => $templateData

        ]);

    }





    /**

     * 获取JSSDK的配置数组

     * @param string $url

     * @param array $APIs

     * @param bool $debug

     * @param bool $beta

     * @param bool $json

     * @return mixed

     */

    public function getJssdkConfig(string $url,array $APIs, $debug = false, $beta = false, $json = true){

        if(!empty($url)){

            $this->officialAccount->jssdk->setUrl($url);

        }

        return $this->officialAccount->jssdk->buildConfig($APIs, $debug, $beta, $json);

    }





    /**

     * 获取授权

     */

    public function oauth(){

        return $this->officialAccount->oauth;

    }



    public function getUserInfo(){

        return $this->officialAccount->user;

    }





    /**

     * 根据模版标题获取模版id

     * @param $templateTitle

     * @return array | bool

     */

    private function accordingTitleGetTemplateId($templateTitle){

        $data = $this->getTemplateList();

        if(empty($data)){

            return false;

        }


        foreach ($data['template_list'] as $val){

            if($val['title'] == $templateTitle){

                return $val;

            }

        }

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

        $start = 0;

        foreach ($arr as $key => $value) {

            $newArr[$key] = $data[$start];

            $start++; 

        }

        return $newArr;

    }









}