<?php

namespace App\Services;

use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Storage;
class ExoProcessDoc
{
    private $template;
    private $new_name_path;
    private $target_dir;
    private $content;
    private $dsn;
    private $directory;
    private $files;

    private $correct_position = [
        'A' => 0,
        'B' => 1,
        'C' => 2,
        'D' => 3,
        'E' => 4,
        'F' => 5,
        'G' => 6,
        'H' => 7
    ];

    private $supported_image = [
        'gif',
        'jpg',
        'jpeg',
        'png'
    ];

    public function __construct($template, $filepath, $directory)
    {
        $this->template = $template;
        $this->directory = $directory;
        $this->target_dir = storage_path('app/public/'.$directory->slug.'/');
        // $this->new_name_path = $new_path;

        $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
            || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $hos = request()->server('HTTP_HOST');
        $this->dsn = $protocol.$hos;

        $target_file = $filepath;
        $info = pathinfo($target_file);
        $new_name = $info['filename']. '.Zip';
        $this->new_name_path = storage_path('app/public/'.$directory->slug.'/'.$new_name);

        rename($target_file, $this->new_name_path);
    }

    private function _xml_attribute($obj, $attr)
    {
        if(isset($obj[$attr]))
	    return (string) $obj[$attr];
    }

    private function _dom_inner_html(\DOMNode $element)
    {
        $innerHTML = "";
        $children  = $element->childNodes;

        foreach ($children as $child)
        {
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }

        return $innerHTML;
    }

    private function _extract_zip()
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->new_name_path) == true ) {
            $zip->extractTo($this->target_dir);
            $zip->close();
        }
    }

    private function _get_xml_content()
    {
        $word_xml = $this->target_dir."word/document.xml";
        $word_xml_relation = $this->target_dir."word/_rels/document.xml.rels";

        $this->content = file_get_contents($word_xml);
        $this->_strip_tags();
        $xml = simplexml_load_file($word_xml_relation);
        $this->_strip_tag_images($xml);
    }

    private function _strip_tags()
    {
        $this->content = strip_tags($this->content, "<a:blip><w:p><w:tbl><w:tr><w:tc>");
        $this->content = preg_replace("/<w:p[^>]*>/is", "<p>", $this->content);
        $this->content = preg_replace("/<\/w:p>/is", "</p>", $this->content);

        $this->content = preg_replace("/<w:tbl[^>]*>/is", '<table class="border-collapse border border-gray-300">', $this->content);
        $this->content = preg_replace("/<\/w:tbl>/is", "</table>", $this->content);

        $this->content = preg_replace("/<w:tr[^>]*>/is", "<tr>", $this->content);
        $this->content = preg_replace("/<\/w:tr>/is", "</tr>", $this->content);

        $this->content = preg_replace("/<w:tc[^>]*>/is", '<td class="border border-gray-300">', $this->content);
        $this->content = preg_replace("/<\/w:tc>/is", "</td>", $this->content);
    }

    private function _strip_tag_images($xml)
    {
        $word_folder = $this->target_dir."word";
		$prop_folder = $this->target_dir."docProps";
		$relat_folder = $this->target_dir."_rels";
        $content_folder = $this->target_dir."[Content_Types].xml";

        $relation_image = [];
        foreach ($xml as $key => $qjd) {
            $ext = strtolower(pathinfo($qjd['Target'], PATHINFO_EXTENSION));
            if(in_array($ext, $this->supported_image)) {
                $id = $this->_xml_attribute($qjd, 'Id');
                $target = $this->_xml_attribute($qjd, 'Target');

                $relation_image[$id] = $target;
            }
        }

        $word_folder = $this->target_dir."word";

        $images = [];
        $iterate = 1;
        foreach ($relation_image as $key => $value) {
            $rplc_str = '<a:blip r:embed="'.$key.'" cstate="print"/>';
            $rplc_str1 = '<a:blip r:embed="'.$key.'" cstate="print"></a:blip>';
            $rplc_str2 = '<a:blip r:embed="'.$key.'"></a:blip>';
            $rplc_str3 = '<a:blip r:embed="'.$key.'"/>';

            $ext_img = strtolower(pathinfo($value, PATHINFO_EXTENSION));
            $imagenew_name=time().$iterate.".".$ext_img;
            $old_path=$word_folder."/".$value;
            $new_path=$this->target_dir.$imagenew_name;

            $image = Image::make($old_path)->encode('webp', 90);
            $new_path_storage = "public/".$this->directory->slug.'/'.$imagenew_name.'.webp';
            Storage::put($new_path_storage, $image->__toString());

            array_push($images, [
                'id'            => Str::uuid()->toString(),
                'directory_id'	=> $this->directory->id,
                'filename'		=> $imagenew_name.'.webp',
                'path'			=> $new_path,
                'exstension'	=> $ext_img,
                'dirname'		=> $this->directory->slug,
                'size'			=> 0,
                'created_at'    => now(),
                'updated_at'    => now()
            ]);

            rename($old_path,$new_path);
            $img = '<img src="'.$this->dsn.'/storage/'.$this->directory->slug.'/'.$imagenew_name.'.webp">';;
            $this->content = str_replace($rplc_str,$img,$this->content);
            $this->content = str_replace($rplc_str1,$img,$this->content);
            $this->content = str_replace($rplc_str2,$img,$this->content);
            $this->content = str_replace($rplc_str3,$img,$this->content);
            $iterate++;
        }
        $this->files = $images;

        $this->_rrmdir($word_folder);
		$this->_rrmdir($relat_folder);
		$this->_rrmdir($prop_folder);
		$this->_rrmdir($content_folder);
		$this->_rrmdir($this->new_name_path);
    }

    private function _rrmdir($dir)
	{
	    if (is_dir($dir)) {
	        $objects = scandir($dir);
	        foreach ($objects as $object) {
	            if ($object != "." && $object != "..") {
	                if (filetype($dir."/".$object) == "dir") {
                        $this->_rrmdir($dir."/".$object);
                    } else {
                        unlink($dir."/".$object);
                    }
	            }
	        }
	        reset($objects);
	        if($dir!="uploads"){
	            rmdir($dir);
	        }
	    }else{
	        unlink($dir);
	    }
	}

    public function render()
    {
        try {
            $this->_extract_zip();
            $this->_get_xml_content();

            $doc = new \DOMDocument();
            $doc->loadHTML('<?xml encoding="utf-8" ?>' .$this->content);

            $data = [];
            $body = $doc->getElementsByTagName('body');
            if ( $body && 0 < $body->length ) {
                $body = $body->item(0);
                foreach($body->childNodes as $table) {
                    if ($table->nodeName == "table") {
                        $element = [
                            'pertanyaan' => '',
                            'correct'    => [],
                            'options'    => [],
                            'type'       => 0,
                        ];
                        foreach($table->childNodes as $iterate => $tr) {
                            $td = $tr->childNodes;
                            $key = $td->item(0);
                            $value = $td->item(1);

                            if ($iterate == 0) {
                                $element['pertanyaan'] = $this->_dom_inner_html($value);
                                continue;
                            }

                            if ($key->nodeValue == ":::") {
                                $correct_op = array_filter(explode(',',$value->nodeValue));
                                $correct_option_position = array();
                                foreach($correct_op as $v){
                                    $correct_option_position[] = $this->correct_position[trim(strip_tags(html_entity_decode($v)))];
                                }

                                $element['correct'] = $correct_option_position;
                                continue;
                            }
                            array_push($element['options'], $this->_dom_inner_html($value));
                        }
                        if (count($element['correct']) > 1) {
                            $element['type'] = 4;
                        } else if (count($element['correct']) == 1) {
                            $element['type'] = 1;
                        } else if (count($element['correct']) == 0) {
                            if (count($element['options']) > 0) {
                                $element['type'] = 6;
                            } else {
                                $element['type'] = 2;
                            }
                        }
                        array_push($data, $element);
                    }
                }
            }

            return [
                'data' => $data,
                'files' => $this->files,
            ];
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
