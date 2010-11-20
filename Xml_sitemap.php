<?php

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter Xml_sitemap Class
 *
 * Xml sitemap oluşturucu.
 *
 * @package        	CodeIgniter
 * @subpackage    	Libraries
 * @category    	Libraries
 * @author        	Mustafa Navruz
 * @license             http://en.wikipedia.org/wiki/MIT_License
 * @link		http://www.navruz.net/codeigniter-xml-sitemap-kutuhanesi/
 * @version		0.1
 */
class Xml_sitemap
{

    private $ci;
    //xml dosya adı. Bu dosyayı manuel olarak ana dizin içerisinde oluşturmalısınız.
    private $file_name = 'sitemap.xml';
    //Site haritasını oluşturduktan sonra arama motorlarına ping atma seçeneği.
    private $ping = TRUE;
    //Ping atılacak servisler
    private $services = array(
        'Google' => 'http://www.google.com/webmasters/tools/ping?sitemap=%s',
        'Yahoo' => 'http://search.yahooapis.com/SiteExplorerService/V1/ping?sitemap=%s',
        'Bing' => 'http://www.bing.com/webmaster/ping.aspx?siteMap=%s',
    );
    //Arama motorlarından ping'in alınıp alınmadığına dair gelen cevaplar.
    private $response = array();
    //Site haritamıze ekleyeceğimiz öğelerin tutulacağı dizi.
    private $items = array();
    //Site haritalarındaki öğelerin alacağı maksimum priority değeri
    private $default_priority = 0.8;
    //Site haritalarındaki öğelerin alacağı minimum priority değeri
    private $min_priority = 0.2;

    /**
     * Yapıcı fonksiyon
     * @param array $params
     */
    public function __construct($params = array())
    {
        $this->ci = & get_instance();
        $this->ci->load->helpers(array('file', 'xml', 'url'));
        if (is_array($params) && count($params) > 0)
        {
            $this->initialize($params);
        }
    }

    /**
     *
     * @param array $params
     * @return
     */
    public function initialize($params)
    {
        if (!is_array($params) && count($params) == 0)
        {
            return;
        }
        foreach ($params as $key => $value)
        {
            if (isset($this->{$key}))
            {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Site haritamıza öğe ekleyen metod. Bu dizinin öğeleri aşağıdaki formatta olmalıdır.
     * $items[] = array(
     *      'slug'=> 'dosya adresi',
     *      'created_on'=> 'oluşturulma tarihi',
     *      'updated_on'=> 'güncellenme tarihi (Bu alan zorunlu değil)',
     *      'comment'=>'Yorum sayısı (Bu alan zorunlu değil)'
     * );
     * 
     * @param array $items
     */
    public function add($items)
    {
        if (is_array($items) && count($items) > 0)
        {
            foreach ($items as $item)
            {
                $priority = isset($item['priority']) ? $item['priority'] : $this->calculate_priority($item);
                $changefreq = isset($item['changefreq']) ? $item['changefreq'] : 'weekly';
                $last_update = ($item['updated_on'] > $item['created_on']) ? $item['updated_on'] : $item['created_on'];
                $this->items[] = array(
                    'loc' => site_url($item['slug']),
                    'lastmod' => date('c', $last_update),
                    'changefreq' => $changefreq,
                    'priority' => $priority
                );
            }
        }
    }

    /**
     * Site haritasına eklenecek olan öğenin öncelik değerini hesaplar.
     * $this->default_priority değerine her yorum için 0.1 puan ekler.
     * Yayınlanma tarihinden bugüne kadar geçen ay sayısını 0.1 ile çarparak $this->default_priority değerinden çıkarır.
     * 
     * @param array $item
     * @return int
     */
    private function calculate_priority($item)
    {
        /**
         * increase 0.1 point for 1 comment
         * decrease 0.1 point for 1 month
         *
         * 2592000 = 1 month
         */
        $date_point = ((time() - $item['created_on']) / 2592000) / 10;
        $comment_point = 0;
        if (isset($item['comment']))
        {
            $comment_point = $item['comment'] * 0.1;
        }
        $item_point = $this->default_priority + $comment_point - $date_point;

        if ($item_point > $this->default_priority)
        {
            $item_point = $this->default_priority;
        }
        elseif ($item_point < $this->min_priority)
        {
            $item_point = $this->min_priority;
        }
        return round($item_point, 1);
    }

    /**
     * Site haritasını oluşturarak kaydeder.
     * ping seçeneği aktif edilmişse belirlenen arama motorlarına pin atar ve sonuçları dizi halinde döndürür.
     *
     * @return array
     */
    public function generate()
    {
        $items = $this->sort_items();
        $xml = simplexml_load_string($this->default_xml());
        if (is_array($items) && count($items) > 0)
        {
            foreach ($items as $item)
            {
                $url = $xml->addChild('url');
                $url->addChild('loc', xml_convert($item['loc']));
                $url->addChild('lastmod', $item['lastmod']);
                $url->addChild('changefreq', $item['changefreq']);
                $url->addChild('priority', $item['priority']);
            }
        }

        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $this->write($dom->saveXML());
        if ($this->ping)
        {
            foreach ($this->services as $name => $url)
            {
                $url = sprintf($url, urlencode($this->gz_file_name(TRUE)));
                $this->response[$name] = $this->send_ping($url);

                if ($this->response[$name] === FALSE)
                {
                    log_message('error', 'Xml_sitemap: ' . $name . ' not pinged!');
                }
            }
        }
        return $this->response;
    }

    /**
     * Oluşturulan site haritasını xml ve xml.gz dosyalarına kaydeder.
     *
     * @param string $data
     * @return boolean
     */
    private function write($data)
    {
        if (write_file($this->file_name, $data))
        {
            write_file($this->gz_file_name(), gzencode($data, 9));
            log_message('debug', "Xml_sitemap: Sitemap Updated");
            return TRUE;
        }
        else
        {
            log_message('error', 'Xml_sitemap: ' . $this->file_name . ' not write!');
        }
        return FALSE;
    }

    /**
     * Site haritamızı oluştururken kullandığımız temel xml elemanımız.
     * @return string
     */
    private function default_xml()
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <!-- generated-date="' . date('r') . '" -->
        <urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        </urlset>';
    }

    /**
     * Belirlenen url'ye curl kütüphanisini kullanarak pin atar.
     *
     * @param string $url
     * @return boolean
     */
    private function send_ping($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_REFERER, base_url());
        curl_setopt($ch, CURLOPT_USERAGENT, 'Codeigniter');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        if (!curl_errno($ch))
        {
            $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ((int) $response == 200)
            {
                $return = TRUE;
            }
        }
        curl_close($ch);
        return isset($return) ? $return : FALSE;
    }

    /**
     * Belirlediğimiz xml dosyası ile aynı isimle oluşturduğumuz xml.gz dosyasının adını veya adresini döndürür.
     *
     * @param boolean $with_url
     * @return strıng
     */
    private function gz_file_name($with_url = FALSE)
    {
        if ($with_url)
        {
            $file_name = base_url() . $this->file_name . '.gz';
        }
        else
        {
            $file_name = $this->file_name . '.gz';
        }
        return $file_name;
    }

    /**
     * Site haritasını oluşturma aşamasında eklediğimiz öğeleri tarihlerine göre sıralar.
     * 
     * @return array
     */
    private function sort_items()
    {
        $_sort = array();
        $_temp = array();
        foreach ($this->items as $item)
        {
            $_sort[$item['loc']] = $item;
            $_temp[$item['lastmod'] . rand(1000, 9999)] = $item['loc'];
        }
        krsort($_temp);
        $sorted_items = array();
        foreach ($_temp as $temp)
        {
            $sorted_items[] = $_sort[$temp];
        }
        unset($_temp, $_sort);
        return $sorted_items;
    }

    /**
     * Ping işlemine verilen cevapları döndürü.
     *
     * @return array
     */
    public function get_response()
    {
        return $this->response;
    }

}

/* End of file Xml_sitemap.php */