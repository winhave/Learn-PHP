<?php
namespace Admin\Model;
use Think\Model;

class CollectModel extends Model {
	
	private $Collect;
	
	
	/* 用户模型自动验证 */
	protected $_validate = array(
		array('pname', 'require', '播放器名称不能为空', self::EXISTS_VALIDATE, 'regex'),
	);
	
	function __construct(){ //导入采集类
		$this->Collect = new \Org\Net\Http;
    }
		
	public function insertbind(){
		if(I('category')){
			if(!$this->checkCategory(I('category'))){
				$this->error="该分类下还有分类请选择下属分类";
				return false;
			}
		}
		$bindcache = F('Type_bind');
		if (!is_array($bindcache)) {
			$bindcache = array();
			$bindcache['1_1'] = 0;
		}
		$bindkey = I('bind');
		$bindinsert[$bindkey] = I('category',0);
		$bindarray = array_merge($bindcache,$bindinsert);
		F('Type_bind',$bindarray);
		return true;
	}
	
	public function insert($date){
		$Mmodel=M("Movie");
	 	if(empty($date['play'])){
			return array("title"=>$date["title"],"content"=>'播放地址为空，不处理!');
		}
		if(!$date['category']){
			return array("title"=>$date["title"],"content"=>'未检测到绑定分类，不处理!');
		}
		$date['actors'] = $this->glactor($date['actors']);
		$date['cover_id']=D("Picture")->down_load($date["pic"]);
		$date['update_time'] = $date["last"];
		$date['pinyin'] = D('Movie')->get_letter($date['title']);
		$dateplay[]=$date['play'];
		unset($date['mid'],$date['tid'],$date['pic'],$date['type'],$date['last']);
		// 检测来源是否完全相同
		$array = $Mmodel->field('id,title')->where("reurl='".$date['reurl']."'")->find();
		if($array){
			return $this->update($date,$array);
		}
		// 检测影片名称是否相等(需防止同名的电影与电视冲突)
		$array = $Mmodel->field('id,title,actors')->where('title="'.$date['title'].'" ')->find();
		if($array){
			//无主演 或 演员完全相等时 更新该影片
			if(empty($date['actors']) || ($array['actors'] == $date['actors'])){
				return $this->update($date,$array);
			}
			//有相同演员时更新该影片
			$arr_actor_1 = explode(',', $date['actors']);
			$arr_actor_2 = explode(',', $array['actors']);
			if(array_intersect($arr_actor_1,$arr_actor_2)){
				return $this->update($actors,$array);
			}
		}
		unset($date['play']);
		$date['create_time'] = NOW_TIME;
		$id = $Mmodel->data($date)->add();
		foreach($dateplay as $key=>$value){
			foreach($value as $k=>$v){
				$play[]=array("movie_url"=>$v['url'],"movie_player_id"=>bind_play($v['name']),"movie_id"=>$id);
			}
		}
		M("movie_url")->addAll($play);
		return array("title"=>$date["title"],"content"=>'添加影片成功!');
	}
	
	public function update($date,$array){
		$date["id"]=$array["id"];
		$dateplay[]=$date['play'];
		unset($date['play']);
		M("Movie")->data($date)->save();
		foreach($dateplay as $key=>$value){
			foreach($value as $k=>$v){
				$play["movie_player_id"]=bind_play($v['name']);
				$play["movie_id"]=$date["id"];
				$play['_logic'] = 'AND';
				$playId=M("Movie_url")->where($play)->getField('id');
				M("Movie_url")->add(array("id"=>$playId,"movie_url"=>$v['url'],"movie_player_id"=>bind_play($v['name']),"movie_id"=>$date["id"]),array(),true);
			}
		}
		return array("title"=>$date["title"],"content"=>'更新影片成功!');
	}
	
	protected function glactor($actor){
		return str_replace(array(',','/','，','|','、',' '),',',$actor);	
	}
	
	protected function checkCategory($cate_id){
		 $child = M('Category')->where(array('pid'=>$cate_id))->field('id')->select();
        if(!empty($child)){
            return false;
        }
        return true;
	}
}