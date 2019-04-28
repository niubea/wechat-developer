<?php

// +----------------------------------------------------------------------
// | WeChatDeveloper
// +----------------------------------------------------------------------
// | 版权所有 2014~2018 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://think.ctolog.com
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/czt/WeChatDeveloper
// +----------------------------------------------------------------------

namespace WeChat\Contracts;

use WeChat\Exceptions\InvalidArgumentException;
use WeChat\Exceptions\InvalidResponseException;
use WeChat\Exceptions\LocalCacheException;

/**
 * 网络请求支持
 * Class Tools
 * @package WeChat\Contracts
 */
class Tools
{
    /**
     * 缓存路径
     * @var null
     */
    public static $cache_path = null;


    /**
     * 产生随机字符串
     * @param int $length 指定字符长度
     * @param string $str 字符串前缀
     * @return string
     */
    public static function createNoncestr($length = 32, $str = "")
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }


    /**
     * 根据文件后缀获取文件MINE
     * @param array $ext 文件后缀
     * @param array $mine 文件后缀MINE信息
     * @return string
     * @throws LocalCacheException
     */
    public static function getExtMine($ext, $mine = [])
    {
        $mines = self::getMines();
        foreach (is_string($ext) ? explode(',', $ext) : $ext as $e) {
            $mine[] = isset($mines[strtolower($e)]) ? $mines[strtolower($e)] : 'application/octet-stream';
        }
        return join(',', array_unique($mine));
    }

    /**
     * 获取所有文件扩展的mine
     * @return array
     * @throws LocalCacheException
     */
    private static function getMines()
    {
        $mines = self::getCache('all_ext_mine');
        if (empty($mines)) {
            $content = file_get_contents('http://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types');
            preg_match_all('#^([^\s]{2,}?)\s+(.+?)$#ism', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) foreach (explode(" ", $match[2]) as $ext) {
                $mines[$ext] = $match[1];
            }
            self::setCache('all_ext_mine', $mines);
        }
        return $mines;
    }

    /**
     * 创建CURL文件对象
     * @param $filename
     * @param string $mimetype
     * @param string $postname
     * @return \CURLFile|string
     * @throws LocalCacheException
     */
    public static function createCurlFile($filename, $mimetype = null, $postname = null)
    {
        is_null($postname) && $postname = basename($filename);
        is_null($mimetype) && $mimetype = self::getExtMine(pathinfo($filename, 4));
        if (function_exists('curl_file_create')) {
            return curl_file_create($filename, $mimetype, $postname);
        }
        return "@{$filename};filename={$postname};type={$mimetype}";
    }

    /**
     * 数组转XML内容
     * @param array $data
     * @return string
     */
    public static function arr2xml($data)
    {
        return "<xml>" . self::_arr2xml($data) . "</xml>";
    }

    /**
     * XML内容生成
     * @param array $data 数据
     * @param string $content
     * @return string
     */
    private static function _arr2xml($data, $content = '')
    {
        foreach ($data as $key => $val) {
            is_numeric($key) && $key = 'item';
            $content .= "<{$key}>";
            if (is_array($val) || is_object($val)) {
                $content .= self::_arr2xml($val);
            } elseif (is_string($val)) {
                $content .= '<![CDATA[' . preg_replace("/[\\x00-\\x08\\x0b-\\x0c\\x0e-\\x1f]/", '', $val) . ']]>';
            } else {
                $content .= $val;
            }
            $content .= "</{$key}>";
        }
        return $content;
    }

    /**
     * 解析XML内容到数组
     * @param string $xml
     * @return array
     */
    public static function xml2arr($xml)
    {
        if(empty($xml) || !is_string($xml)){
            return [];
        }
        $xml = htmlspecialchars_decode($xml);
        if(self::xml_parser($xml)){
            $entity = libxml_disable_entity_loader(true);
            $data = (array)simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_disable_entity_loader($entity);
            return json_decode(self::arr2json($data), true);
        }else{
            return [];
        }
    }

    /**
     * 验证XML是否正确
     * @param string $xml
     * @return bool
     */
    public static function xml_parser($str){
        $xml_parser = xml_parser_create();
        if(!xml_parse($xml_parser,$str,true)){
          xml_parser_free($xml_parser);
          return false;
        }else {
          return true;
        }
    }

    /**
     * 数组转xml内容
     * @param array $data
     * @return null|string|string
     */
    public static function arr2json($data)
    {
        return preg_replace_callback('/\\\\u([0-9a-f]{4})/i', function ($matches) {
            return mb_convert_encoding(pack("H*", $matches[1]), "UTF-8", "UCS-2BE");
        }, ($jsonData = json_encode($data)) == '[]' ? '{}' : $jsonData);
    }

    /**
     * 解析JSON内容到数组
     * @param string $json
     * @return array
     * @throws InvalidResponseException
     */
    public static function json2arr($json)
    {
        $result = json_decode($json, true);
        if (empty($result)) {
            throw new InvalidResponseException('invalid response.', '0');
        }
        if (!empty($result['errcode'])) {
            throw new InvalidResponseException($result['errmsg'], $result['errcode'], $result);
        }
        return $result;
    }

    /**
     * 以get访问模拟访问
     * @param string $url 访问URL
     * @param array $query GET数
     * @param array $options
     * @return bool|string
     */
    public static function get($url, $query = [], $options = [])
    {
        $options['query'] = $query;
        return self::doRequest('get', $url, $options);
    }

    /**
     * 以post访问模拟访问
     * @param string $url 访问URL
     * @param array $data POST数据
     * @param array $options
     * @return bool|string
     */
    public static function post($url, $data = [], $options = [])
    {
        $options['data'] = $data;
        return self::doRequest('post', $url, $options);
    }

    /**
     * CURL模拟网络请求
     * @param string $method 请求方法
     * @param string $url 请求方法
     * @param array $options 请求参数[headers,data,ssl_cer,ssl_key]
     * @return bool|string
     */
    protected static function doRequest($method, $url, $options = [])
    {
        $curl = curl_init();
        // GET参数设置
        if (!empty($options['query'])) {
            $url .= (stripos($url, '?') !== false ? '&' : '?') . http_build_query($options['query']);
        }
        // CURL头信息设置
        if (!empty($options['headers'])) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $options['headers']);
        }
        // POST数据设置
        if (strtolower($method) === 'post') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $options['data']);
        }
        // 证书文件设置
        if (!empty($options['ssl_cer'])) {
            if (file_exists($options['ssl_cer'])) {
                curl_setopt($curl, CURLOPT_SSLCERTTYPE, 'PEM');
                curl_setopt($curl, CURLOPT_SSLCERT, $options['ssl_cer']);
            } else {
                throw new InvalidArgumentException("Certificate files that do not exist. --- [ssl_cer]");
            }
        }
        // 证书文件设置
        if (!empty($options['ssl_key'])) {
            if (file_exists($options['ssl_key'])) {
                curl_setopt($curl, CURLOPT_SSLKEYTYPE, 'PEM');
                curl_setopt($curl, CURLOPT_SSLKEY, $options['ssl_key']);
            } else {
                throw new InvalidArgumentException("Certificate files that do not exist. --- [ssl_key]");
            }
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        list($content, $status) = [curl_exec($curl), curl_getinfo($curl), curl_close($curl)];
        return (intval($status["http_code"]) === 200) ? $content : false;
    }

    /**
     * 写入文件
     * @param string $name 文件名称
     * @param string $content 文件内容
     * @return string
     * @throws LocalCacheException
     */
    public static function pushFile($name, $content)
    {
        $file = self::getCacheName($name);
        if (!file_put_contents($file, $content)) throw new LocalCacheException('local file write error.', '0');
        return $file;
    }

    /**
     * 缓存配置与存储
     * @param string $name 缓存名称
     * @param string $value 缓存内容
     * @param int $expired 缓存时间(0表示永久缓存)
     * @return string
     * @throws LocalCacheException
     */
    public static function setCache($name, $value = '', $expired = 3600)
    {
        $content = serialize(['name' => $name, 'value' => $value, 'expired' => time() + intval($expired)]);
        if(strpos($name, '_access_token') === false) {
            $file = self::getCacheName($name);
            if (!file_put_contents($file, $content)) throw new LocalCacheException('local cache error.', '0');
            return $file;
        } else {
            redis()->setex($name, 7000, $content);
            return $name;
        }
    }

    /**
     * 获取缓存内容
     * @param string $name 缓存名称
     * @return null|mixed
     */
    public static function getCache($name)
    {
        if(strpos($name, '_access_token') === false) {
            $file = self::getCacheName($name);
            if (file_exists($file) && ($content = file_get_contents($file))) {
                $data = unserialize($content);
                if (isset($data['expired']) && (intval($data['expired']) === 0 || intval($data['expired']) >= time())) {
                    return $data['value'];
                }
                self::delCache($name);
            }
            return null;
        } else {
            $content = redis()->get($name);
            if(!empty($content)) {
                $data = unserialize($content);
                if (isset($data['expired']) && (intval($data['expired']) === 0 || intval($data['expired']) >= time())) {
                    return $data['value'];
                }
                self::delCache($name);
            }
            return null;
        }
    }

    /**
     * 移除缓存文件
     * @param string $name 缓存名称
     * @return bool
     */
    public static function delCache($name)
    {
        if(strpos($name, '_access_token') === false) {
            $file = self::getCacheName($name);
            return file_exists($file) ? unlink($file) : true;
        }
        return redis()->exists($name) ? redis()->del($name) : true;
    }

    /**
     * 应用缓存目录
     * @param string $name
     * @return string
     */
    private static function getCacheName($name)
    {
        if (empty(self::$cache_path)) {
            self::$cache_path = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR;
        }
        self::$cache_path = rtrim(self::$cache_path, '/\\') . DIRECTORY_SEPARATOR;
        file_exists(self::$cache_path) || mkdir(self::$cache_path, 0755, true);
        return self::$cache_path . $name;
    }
}