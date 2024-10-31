<?php
   /*
   Plugin Name: Post to Flarum
   Plugin URI: https://gitlab.com/malago/postToFlarum
   description: Posts new WordPress posts to Flarum
   Version: 0.3.4
   Author: Miguel A. Lago
   Author URI: https://gitlab.com/malago/
   License: GPL2
   */

	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

    include("htmltomarkdown/HtmlConverterInterface.php");	
    include("htmltomarkdown/HtmlConverter.php");
	
	include("htmltomarkdown/Environment.php");
	include("htmltomarkdown/ElementInterface.php");
	include("htmltomarkdown/Element.php");
	include("htmltomarkdown/ConfigurationAwareInterface.php");
	include("htmltomarkdown/Configuration.php");
	
	include("htmltomarkdown/Converter/ConverterInterface.php");
	include("htmltomarkdown/Converter/BlockquoteConverter.php");
	include("htmltomarkdown/Converter/CodeConverter.php");
	include("htmltomarkdown/Converter/CommentConverter.php");
	
	include("htmltomarkdown/Converter/DefaultConverter.php");
	include("htmltomarkdown/Converter/DivConverter.php");
	include("htmltomarkdown/Converter/EmphasisConverter.php");
	include("htmltomarkdown/Converter/HardBreakConverter.php");
	include("htmltomarkdown/Converter/HeaderConverter.php");
	include("htmltomarkdown/Converter/HorizontalRuleConverter.php");
	include("htmltomarkdown/Converter/ImageConverter.php");
	include("htmltomarkdown/Converter/LinkConverter.php");
	include("htmltomarkdown/Converter/ListBlockConverter.php");
	include("htmltomarkdown/Converter/ListItemConverter.php");
	include("htmltomarkdown/Converter/ParagraphConverter.php");
	include("htmltomarkdown/Converter/PreformattedConverter.php");
	include("htmltomarkdown/Converter/TextConverter.php");
	include("htmltomarkdown/Converter/TableConverter.php");

	function postToFlarum($post, $scheduled=0){
		if(!$scheduled){
			if(! current_user_can( 'edit_posts' ) ) return;
			if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
			if ( wp_is_post_revision( $post->ID ) ) return;
			if ( wp_is_post_autosave($post->ID)) return;
		}

		

		$activated = get_option('_posttoflarum_activated');
		if($activated && ($scheduled || $post->post_status=='publish')){
			//file_put_contents("log1.txt","save-".$post->ID."-sch".$scheduled."\n",FILE_APPEND);
			$post_id=$post->ID;
			

			$html=preg_replace('/<!--(.*)-->/Uis', '', $post->post_content);
			$html=str_replace("</figure>","</figure><br>",$html);

			$html=postToFlarum_replaceCustomTags($html);
			$html=do_shortcode($html);
			
			//file_put_contents("log.txt",print_r($html,true));
            
			$converter =new League\HTMLToMarkdown\HtmlConverter(array('strip_tags' => true, 'hard_break', false));
			$md = $converter->convert($html);	

			$tags=postToFlarum_getMatchingTags($post);
			$user=postToFlarum_getMatchingUser($post);
			$link=postToFlarum_getPreviouslyPosted($post);

			//file_put_contents("log1.txt",date("r")." | tags-".print_r($tags,true)."\n",FILE_APPEND);
			
			if(sizeof($link)==0){
				$discussionID=postToFlarum_createDiscussionFlarum($post,$md,$tags,$user);
				if(get_option('_posttoflarum_create_link')==1 && $discussionID!=-1){
					postToFlarum_createLink($post->ID,$discussionID,$post->post_title,1);
				}
			}else{
				$discussionID=postToFlarum_updateDiscussionFlarum($link[0]->post_id,$link[0]->topic_id,$post,$md,$tags,$user);
				if(get_option('_posttoflarum_create_link')==1 && $discussionID==true){
					postToFlarum_createLink($post->ID,$link[0]->topic_id,$post->post_title,0);
				}
			}

			//file_put_contents("log1.txt",date("r")." | discussion-".$discussionID."\n",FILE_APPEND);
			
			
			if($discussionID==-1){
				//add_action( 'admin_notices', 'postToFlarum_error' );
			}
		}
		remove_action('save_post', 'postToFlarum_save' );
		remove_action('post_updated','postToFlarum_save');
		remove_action('transition_post_status', 'postToFlarum_scheduled');
	}

	function postToFlarum_replaceCustomTags($html){
		global $wpdb;
		$tags=$wpdb->get_results("SELECT * from " .($wpdb->prefix . 'posttoflarum')." order by id asc");

		//file_put_contents("log.txt",date("r")." | ".$html."\n");

		foreach($tags as $tag){
            $htmltag=str_replace('\\\\','\\',$tag->html_tag);
			$html = preg_replace(html_entity_decode($htmltag), html_entity_decode($tag->bbcode_tag), $html);
		}

		return $html;
	}
	
	function postToFlarum_createLink($postID,$discussionID,$title,$new){

		$slug = sanitize_title(sanitize_title($title, '', 'save'), '', 'query');

		$forumUrl = rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/");
		$r=$forumUrl.'/d/'.$discussionID.'-'.$slug;

		global $wpdb;
		if($new){
			$e=$wpdb->insert($wpdb->prefix.'postmeta',array(
				'post_id' => $postID,
				'meta_key' => "_links_to",
				'meta_value' => $r
			));
		}else{
			$e=$wpdb->update($wpdb->prefix.'postmeta',
				array(
					'meta_value' => $r,
				),
				array('post_id'=>$postID,'meta_key' => "_links_to")
			);
		}
	}

	function postToFlarum_slugify($string, $wordLimit = 0)
	{
	    $separator = '-';
    
		if($wordLimit != 0){
			$wordArr = explode(' ', $string);
			$string = implode(' ', array_slice($wordArr, 0, $wordLimit));
		}

		$quoteSeparator = preg_quote($separator, '#');

		$trans = array(
			'&.+?;'                    => '',
			'[^\w\d _-]'            => '',
			'\s+'                    => $separator,
			'('.$quoteSeparator.')+'=> $separator
		);

		$string = strip_tags($string);
		foreach ($trans as $key => $val){
			$string = preg_replace('#'.$key.'#iu', $val, $string);
		}

		$string = strtolower($string);

		return trim(trim($string, $separator));
	}

 
	function postToFlarum_admin(){
        add_management_page( 'Post to Flarum', 'Post to Flarum', 'manage_options', 'postToFlarum', 'postToFlarum_admin_init', 'dashicons-admin-comments');
	}
	
	function postToFlarum_admin_init(){
		if(!is_admin()) return;
		global $wpdb;
		$table_name = $wpdb->prefix . 'posttoflarum';
		
		if(isset($_POST['_posttoflarum_delete'])){
			$idCode=(int)($_POST['_posttoflarum_delete']);

			$wpdb->delete($table_name, array('id'=>$idCode));
		}elseif(isset($_POST['_posttoflarum_numbbcode'])){			
			$numTags=(int)($_POST['_posttoflarum_numbbcode']);
			for($i=0;$i<$numTags;$i++){
				$html_tag=(htmlentities($_POST['_posttoflarum_html'.$i]));
				$bbcode_tag=(htmlentities($_POST['_posttoflarum_bbcode'.$i]));
				$idCode=(int)($_POST['_posttoflarum_id'.$i]);

				$html_tag=str_replace('\\&quot;','&quot;',$html_tag);
				$bbcode_tag=str_replace('\\&quot;','&quot;',$bbcode_tag);
				
				if (!(empty($html_tag) && empty($bbcode_tag))){
					$default_row = $wpdb->get_row("SELECT * FROM $table_name where id = $idCode" );
					
					if ( $default_row == null ) {
						$item = array(
						 'html_tag' => ($html_tag),
						 'bbcode_tag' => ($bbcode_tag),
						);

						$wpdb->insert( $table_name, $item );
						if ($i==7)
							$wpdb->last_query;
					}else{
						$wpdb->update($table_name, 
							array(
							 'html_tag' => ($html_tag),
							 'bbcode_tag' => ($bbcode_tag),
							),
							array('id'=>$idCode)
						);
					}
				}
			}
		}
		
		$tags=$wpdb->get_results("SELECT * from " .$table_name." order by id asc");
		
		?>
		<div class="wrap">
		<h1>Post to Flarum</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'post-to-flarum' ); ?>
			<?php do_settings_sections( 'post-to-flarum' ); ?>
			<table class="form-table">
				<tr>
				<th scope="row">Activated</th>
				<td><input type="checkbox" name="_posttoflarum_activated" value="1" <?php echo esc_attr( get_option('_posttoflarum_activated') )?"checked":""; ?> /></td>
				</tr>

				<tr>
				<th scope="row">Flarum token (<a href="https://github.com/flagrow/flarum-api-client#configuration" target="_blank">how to create</a>)</th>
				<td><input type="text" size="50" name="_posttoflarum_token" value="<?php echo esc_attr( get_option('_posttoflarum_token') ); ?>" /></td>
				</tr>
				
				<tr>
				<th scope="row">Create link to forum</th>
				<td><input type="checkbox" name="_posttoflarum_create_link" value="1" <?php echo esc_attr( get_option('_posttoflarum_create_link') )?"checked":""; ?>/> (requires <a href="https://wordpress.org/plugins/page-links-to/" target="_blank">Page Links To</a> plugin on WordPress)</td>
				</tr>
				
				<tr>
				<th scope="row">Absolute flarum forum path</th>
				<td><input type="text" size="50" name="_posttoflarum_forum_path" value="<?php echo esc_attr( get_option('_posttoflarum_forum_path') ); ?>" /></td>
				</tr>
				
				<tr>
				<th scope="row">Flarum tag <i>slug</i> to always add, empty to disable</th>
				<td><input type="text" size="50" name="_posttoflarum_forum_id" value="<?php echo esc_attr( get_option('_posttoflarum_forum_id') ); ?>" /></td>
				</tr>
			</table>

			<p>If you want to be able to change the author of the Flarum discussion, you need the <a href="https://github.com/clarkwinkelmann/flarum-ext-author-change/" target="_blank">Author Change extension</a> installed on your Flarum forum. Otherwise, the author will be the one that created the Flarum token.
			
			<?php submit_button(); ?>

		</form>
		<h1>Advanced Options</h1>
		<p>Introduce your customized regular expression to translate HTML code into Flarum Markdown or BBCode. This will be done before the HTML to Markdown process.</p>
		<form method="post" action="?page=postToFlarum">
		<input type="hidden" name="_posttoflarum_numbbcode" value="<?php echo (count($tags)+1);?>" />
		<table class="form-table">
			<tr valign="top">
			<th scope="row">Regular expression for HTML code</th>
			<td>Replace with Markdown or BBCode</td>
			</tr>
		
		<?php
		$i=0;
		foreach($tags as $tag){ ?>
				<tr valign="top">
				<th scope="row"><input type="text" size="50" name="_posttoflarum_html<?php echo $i; ?>" value="<?php 
				
				$htmltag=str_replace('\\\\','\\',$tag->html_tag);
				//$htmltag=str_replace('\"','"',$htmltag);
                echo esc_html($htmltag);
                ?>" /></th>
				<td><input type="text" size="50" name="_posttoflarum_bbcode<?php echo $i; ?>" value="<?php 
				//$bbcode_tag=str_replace('\"','"',$tag->bbcode_tag);
				$bbcode_tag=str_replace('\\\\','\\',$tag->bbcode_tag);
				
                echo (esc_html($bbcode_tag));
                ?>" /> <button class="button-primary" type="submit" name="_posttoflarum_delete" value="<?php echo ($tag->id); ?>" title="Delete">X</button></td>
				<input type="hidden" name="_posttoflarum_id<?php echo $i; ?>" value="<?php echo $tag->id;?>" />
				
				</tr>
		<?php
			$i++;
		}
		?>
			<tr valign="top">
			<th scope="row"><input type="text" size="50" name="_posttoflarum_html<?php echo ($i); ?>" value="" /></th>
			<td><input type="text" size="50" name="_posttoflarum_bbcode<?php echo ($i); ?>" value="" /></td>
			<input type="hidden" name="_posttoflarum_id<?php echo ($i); ?>" value="<?php echo -1;?>" />
			</tr>
		</table>
		<?php submit_button(); ?>
		
		</form>
		</div>
<?php
}

function postToFlarum_register_settings() { // whitelist options
	register_setting( 'post-to-flarum', '_posttoflarum_activated' );
	register_setting( 'post-to-flarum', '_posttoflarum_token' );
	register_setting( 'post-to-flarum', '_posttoflarum_forum_id' );
	register_setting( 'post-to-flarum', '_posttoflarum_forum_path' );
	register_setting( 'post-to-flarum', '_posttoflarum_create_link' );
}

global $post_to_Flarum_db_version;
$post_to_Flarum_db_version = '1.0';

function postToFlarum_install() {
	if(!is_admin()) return;
	global $wpdb;
	global $post_to_Flarum_db_version;

	$table_name = $wpdb->prefix . 'posttoflarum';
	$table_name_posts = $wpdb->prefix . 'posttoflarum_posts';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		html_tag mediumtext NOT NULL,
		bbcode_tag mediumtext NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
	
	$sql = "CREATE TABLE $table_name_posts (
		blog_id int(10) NOT NULL,
		modified int(11) NOT NULL,
		post_id int(11) NOT NULL,
		topic_id int(11) NOT NULL,
		PRIMARY KEY  (blog_id),
		CONSTRAINT combination UNIQUE (blog_id,post_id)
	) $charset_collate;";

	dbDelta( $sql );

	add_option( '_posttoflarum_db_version', $post_to_Flarum_db_version );

	register_uninstall_hook(__FILE__, 'postToFlarum_uninstall');

	$welcome_text = 'Congratulations, you just completed the installation!';
}

function postToFlarum_error($message){
	$class = 'notice notice-error';

	$message="Error";

	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) ); 
	//file_put_contents("log.txt",$message);
}

function postToFlarum_uninstall() {
	if(!is_admin()) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'posttoflarum';
    $table_name_posts = $wpdb->prefix . 'posttoflarum_posts';
    
    $wpdb->query( "DROP TABLE IF EXISTS ".$table_name );
    $wpdb->query( "DROP TABLE IF EXISTS ".$table_name_posts );

	delete_option("_posttoflarum_db_version");
	
	unregister_setting( 'post-to-flarum', '_posttoflarum_activated' );
	unregister_setting( 'post-to-flarum', '_posttoflarum_token' );
	unregister_setting( 'post-to-flarum', '_posttoflarum_forum_id' );
	unregister_setting( 'post-to-flarum', '_posttoflarum_forum_path' );
	unregister_setting( 'post-to-flarum', '_posttoflarum_create_link' );
}

register_activation_hook( __FILE__, 'postToFlarum_install' );

add_action('admin_init', 'postToFlarum_register_settings' );
add_action('admin_menu', 'postToFlarum_admin');
add_action('rest_after_insert_post', 'postToFlarum' , 10, 1 );
add_action('save_post', 'postToFlarum_save', 1000, 3);
add_action('post_updated','postToFlarum_update',1000,3);
add_action('transition_post_status', 'postToFlarum_scheduled' , 10, 3 );
wp_enqueue_style( 'postToFlarum_css', plugins_url( 'style.css', __FILE__ ) );


function postToFlarum_settings_link($links) { 
	$settings_link = '<a href="tools.php?page=postToFlarum">Settings</a>'; 
	array_unshift($links, $settings_link); 
	return $links; 
  }
$plugin = plugin_basename(__FILE__); 
add_filter("plugin_action_links_$plugin", 'postToFlarum_settings_link' );

function postToFlarum_update($post_id,$a,$b){
	if(current_user_can('edit_post',$post_id)){
		//file_put_contents("log1.txt","updating-".$post_id."\n",FILE_APPEND);
		postToFlarum(get_post($post_id));
	}
}

function postToFlarum_save($post_id,$a,$b){
	
	if(current_user_can('edit_post',$post_id)){
		postToFlarum(get_post($post_id));
	}
}

function postToFlarum_scheduled( $new_status, $old_status, $post ) {
	//file_put_contents("log1.txt","scheduled-".$post->ID."\n",FILE_APPEND);
	if ( 'publish' === $new_status && 'future' === $old_status ) {
	   // Do something if the post has been transitioned from the future (scheduled) post to publish.
	   postToFlarum($post,1);
	}
}

function postToFlarum_getPreviouslyPosted($post){
	global $wpdb;
	$sql = 'SELECT blog_id, post_id, topic_id, modified
		FROM '.$wpdb->prefix.'posttoflarum_posts
		WHERE blog_id = ' . $post->ID;
	$resultLinkForoBlog=$wpdb->get_results($sql);

	return $resultLinkForoBlog;
}

function postToFlarum_getMatchingTags($post){
	
	$categoriesWP=get_the_category($post->ID);

	$categories=array();

	$addTag=esc_attr( get_option('_posttoflarum_forum_id') );
	if($addTag!=""){
		array_push($categories,array("slug"=>$addTag));
	}

	foreach($categoriesWP as $cat){
		array_push($categories,array("slug"=>$cat->slug));
	}

	$tags=array();

	
	$args = array('method' => 'GET');
	$response = wp_remote_request( rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/")."/api/tags", $args );
	
	if(!is_wp_error($response)){
		$flarumTags=json_decode($response["body"]);

		//file_put_contents("log2.txt",date("r")." | tagsFL-".print_r($response-.>,true)."\n",FILE_APPEND);

		foreach($categories as $cat){
			foreach($flarumTags->data as $ft){
				//file_put_contents("log1.txt",date("r")." | slug1-".$cat["slug"]." | slug2-". $ft->attributes->slug."\n",FILE_APPEND);
				if(strcmp($cat["slug"],$ft->attributes->slug)==0){
					array_push($tags,$ft);
					break;
				}
			}
		}
	}
    
	//file_put_contents("log.txt",print_r($tags,true).print_r($categories,true));
	
	
	return $tags;
}

function postToFlarum_getMatchingUser($post){
	$header = array(
		"Authorization: Token ".esc_attr( get_option('_posttoflarum_token') ),
		"Content-Type: application/json"
	);

	$author=get_userdata(get_post_field( 'post_author',  $post->ID ));
	$email=$author->data->user_email;

	$args = array(
		'headers' => array(
			'Authorization' => 'Token ' . esc_attr( get_option('_posttoflarum_token') ),
			'Content-Type'  => 'application/json'
		),
		'method' => 'GET'
	);

	$log="";

	$more=1; $found=0;
	$usersPage=rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/")."/api/users";
	do{
		
		
		$flarumUsers=json_decode(wp_remote_request( $usersPage, $args )["body"]);

		foreach($flarumUsers->data as $user){
			if($user->attributes->email==$email){
				$idUserFlarum=$user->id;
				$found=1;
				break;
			}
		}

		if($flarumUsers->links->next){
			$more=1;
			$usersPage=$flarumUsers->links->next;
		}else
			$more=0;

	} while($more && !$found);


	return $idUserFlarum;
}

function postToFlarum_updateDiscussionFlarum($post_id,$discussion_id,$post,$md,$tags,$user){

	$header = array(
		"Authorization: Token ".esc_attr( get_option('_posttoflarum_token') ),
		"Content-Type: application/json"
	);

	$payload=json_encode((array('data' => array(
		'type' => "posts",
		'id' => $post_id,
		'attributes' => array(
				'content' => $md,
				),
		'relationships'=>array(
			"user"=> array(
				"data"=> array(
					"type"=> "users",
					"id"=> $user
					)
				) 
			)
		)
			)));

	$args = array(
		'headers' => array(
			'Authorization' => 'Token ' . esc_attr( get_option('_posttoflarum_token') ),
			'Content-Type'  => 'application/json'
		),
		'body'   => $payload,
		'method' => 'PATCH'
	);
	$result=wp_remote_request( rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/")."/api/posts/".$post_id, $args );

	if($result->errors || is_wp_error($result)){
		return -1;
	}

	//file_put_contents("log.txt","1/api/discussions/".$discussion_id);

	$args = array('method' => 'GET');
	$response = json_decode(wp_remote_request( rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/")."/api/discussions/".$discussion_id, $args )["body"]);

	$currentTags=$response->data->relationships->tags->data;
	$tags1=Array();
	$tags2=Array();

	foreach($currentTags as $t){
		array_push($tags1,$t->id);
	}

	foreach($tags as $t){
		array_push($tags2,$t->id);
	}

	$updatedTags=Array('data'=>$tags);
	if(sort($tags1)==sort($tags2))
		$updatedTags=Array();
	//file_put_contents("log.txt","compare".print_r($updatedTags,true));

	$payload=json_encode((array('data' => array(
		'type' => "discussions",
		'id' => $discussion_id,
		'attributes' => array(
				'title' => $post->post_title,
				),
		'relationships'=>array(
			"tags"=>$updatedTags,
			"user"=> array(
				"data"=> array(
					"type"=> "users",
					"id"=> $user
					)
				) 
			)
		),
		
		)));
	
	$args = array(
		'headers' => array(
			'Authorization' => 'Token ' . esc_attr( get_option('_posttoflarum_token') ),
			'Content-Type'  => 'application/json'
		),
		'body'   => $payload,
		'method' => 'PATCH'
	);
	$result=json_decode(wp_remote_request( rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/")."/api/discussions/".$discussion_id, $args )["body"]);

	if($result->errors){
		//file_put_contents("log.txt","ERROR");
		return -1;
	}

	global $wpdb;	
	$wpdb->update($wpdb->prefix.'posttoflarum_posts',array(
		'modified' => strtotime($post->post_modified)
		), array('post_id' => $post_id));

	return true;
}

function postToFlarum_createDiscussionFlarum($post,$md,$tags,$user){
	
	$header = array(
		"Authorization: Token ".esc_attr( get_option('_posttoflarum_token') ),
		"Content-Type: application/json"
	);

	$payload=json_encode((array('data' => array(
		'type' => "discussions",
		'attributes' => array(
				'title' => $post->post_title,
				'content' => $md,
				),
			'relationships'=>array(
				'tags'=>array(
					'data'=>$tags
					),
				"user"=> array(
					"data"=> array(
						"type"=> "users",
						"id"=> $user
						)
					) 
				)
			)
		)));

	$args = array(
		'headers' => array(
			'Authorization' => 'Token ' . esc_attr( get_option('_posttoflarum_token') ),
			'Content-Type'  => 'application/json'
		),
		'body'   => $payload,
		'method' => 'POST'
	);
	$result=json_decode(wp_remote_request( rtrim(esc_attr( get_option('_posttoflarum_forum_path')),"/")."/api/discussions", $args )["body"]);
    
	//file_put_contents("log.txt",print_r($payload,true));
	if($result->errors){
		return -1;
	}
	global $wpdb;
	
	$wpdb->insert($wpdb->prefix.'posttoflarum_posts',array(
		'topic_id' => $result->data->id,
		'post_id' => $result->included[0]->id,
		'blog_id' => $post->ID,
		'modified' => strtotime($post->post_modified)
	));

	return $result->data->id;
}

?>