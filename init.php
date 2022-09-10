<?php
require_once __DIR__ . "/vendor/autoload.php";

use \fivefilters\Readability\Readability;
use \fivefilters\Readability\Configuration;

class Af_Readability extends Plugin
{

  /** @var PluginHost $host */
  private $host;

  function about()
  {
    return array(
      null,
      "Try to inline article content using Readability",
      "fox"
    );
  }

  function flags()
  {
    return array("needs_curl" => true);
  }

  /** @return void */
  function save()
  {
    $enable_share_anything = checkbox_to_sql_bool($_POST["enable_share_anything"] ?? "");

    $this->host->set($this, "enable_share_anything", $enable_share_anything);

    echo __("Data saved.");
  }

  function init($host)
  {
    $this->host = $host;

    $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    $host->add_hook($host::HOOK_PREFS_TAB, $this);
    $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
    $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
    $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);

    // Note: we have to install the hook even if disabled because init() is being run before plugin data has loaded
    // so we can't check for our storage-set options here
    $host->add_hook($host::HOOK_GET_FULL_TEXT, $this);

    $host->add_filter_action($this, "action_inline", __("Inline content"));
    $host->add_filter_action($this, "action_inline_append", __("Append content"));
  }

  function get_js()
  {
    return file_get_contents(__DIR__ . "/init.js");
  }

  function hook_article_button($line)
  {
    return "<i class='material-icons' onclick=\"Plugins.Af_Readability.embed(" . $line["id"] . ")\"
			style='cursor : pointer' title=\"" . __('Toggle full article text') . "\">description</i>";
  }

  function hook_prefs_tab($args)
  {
    if ($args != "prefFeeds") return;

    $enable_share_anything = sql_bool_to_bool($this->host->get($this, "enable_share_anything"));

?>
    <div dojoType='dijit.layout.AccordionPane' title="<i class='material-icons'>extension</i> <?= __('Readability settings (af_readability)') ?>">

      <?= format_notice("Enable for specific feeds in the feed editor.") ?>

      <form dojoType='dijit.form.Form'>

        <?= \Controls\pluginhandler_tags($this, "save") ?>

        <script type="dojo/method" event="onSubmit" args="evt">
          evt.preventDefault();
          if (this.validate()) {
            Notify.progress('Saving data...', true);
            xhr.post("backend.php", this.getValues(), (reply) => {
              Notify.info(reply);
            })
          }
        </script>

        <fieldset>
          <label class='checkbox'>
            <?= \Controls\checkbox_tag("enable_share_anything", $enable_share_anything) ?>
            <?= __("Provide full-text services to core code (bookmarklets) and other plugins") ?>
          </label>
        </fieldset>

        <hr />

        <?= \Controls\submit_tag(__("Save")) ?>
      </form>

      <?php
      /* cleanup */
      $enabled_feeds = $this->filter_unknown_feeds(
        $this->get_stored_array("enabled_feeds")
      );

      $append_feeds = $this->filter_unknown_feeds(
        $this->get_stored_array("append_feeds")
      );

      $this->host->set($this, "enabled_feeds", $enabled_feeds);
      $this->host->set($this, "append_feeds", $append_feeds);
      ?>

      <?php if (count($enabled_feeds) > 0) { ?>
        <hr />
        <h3><?= __("Currently enabled for (click to edit):") ?></h3>

        <ul class='panel panel-scrollable list list-unstyled'>
          <?php foreach ($enabled_feeds as $f) { ?>
            <li>
              <i class='material-icons'>rss_feed</i>
              <a href='#' onclick="CommonDialogs.editFeed(<?= $f ?>)">
                <?= Feeds::_get_title($f) . " " . (in_array($f, $append_feeds) ? __("(append)") : "") ?>
              </a>
            </li>
          <?php } ?>
        </ul>
      <?php } ?>
    </div>
  <?php
  }

  function hook_prefs_edit_feed($feed_id)
  {
    $enabled_feeds = $this->get_stored_array("enabled_feeds");
    $append_feeds = $this->get_stored_array("append_feeds");
  ?>

    <header><?= __("Readability") ?></header>
    <section>
      <fieldset>
        <label class='checkbox'>
          <?= \Controls\checkbox_tag("af_readability_enabled", in_array($feed_id, $enabled_feeds)) ?>
          <?= __('Inline article content') ?>
        </label>
      </fieldset>
      <fieldset>
        <label class='checkbox'>
          <?= \Controls\checkbox_tag("af_readability_append", in_array($feed_id, $append_feeds)) ?>
          <?= __('Append to summary, instead of replacing it') ?>
        </label>
      </fieldset>
    </section>
<?php
  }

  function hook_prefs_save_feed($feed_id)
  {
    $enabled_feeds = $this->get_stored_array("enabled_feeds");
    $append_feeds = $this->get_stored_array("append_feeds");

    $enable = checkbox_to_sql_bool($_POST["af_readability_enabled"] ?? "");
    $append = checkbox_to_sql_bool($_POST["af_readability_append"] ?? "");

    $enable_key = array_search($feed_id, $enabled_feeds);
    $append_key = array_search($feed_id, $append_feeds);

    if ($enable) {
      if ($enable_key === false) {
        array_push($enabled_feeds, $feed_id);
      }
    } else {
      if ($enable_key !== false) {
        unset($enabled_feeds[$enable_key]);
      }
    }

    if ($append) {
      if ($append_key === false) {
        array_push($append_feeds, $feed_id);
      }
    } else {
      if ($append_key !== false) {
        unset($append_feeds[$append_key]);
      }
    }

    $this->host->set($this, "enabled_feeds", $enabled_feeds);
    $this->host->set($this, "append_feeds", $append_feeds);
  }

  function hook_article_filter_action($article, $action)
  {
    switch ($action) {
      case "action_inline":
        return $this->process_article($article, false);
      case "action_append":
        return $this->process_article($article, true);
    }
    return $article;
  }

  public function extract_content_weibo(string $url)
  {
    // $link_format - http://weibo.com/{uid}/{bid};
    $vars = explode("/", $url);
    $vars_len = count($vars);
    $uid = $vars[$vars_len - 2];
    $bid = $vars[$vars_len - 1];
    $api_url = "https://m.weibo.cn/statuses/show?id={$bid}";

    $curl = curl_init($api_url);
    curl_setopt($curl, CURLOPT_URL, $api_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $headers = array(
      "Referer: https://m.weibo.cn/u/${uid}",
      "MWeibo-Pwa: 1",
      "X-Requested-With: XMLHttpRequest",
      "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 11_0 like Mac OS X) AppleWebKit/604.1.38 (KHTML, like Gecko) Version/11.0 Mobile/15A372 Safari/604.1",
    );
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $output = @json_decode(curl_exec($curl));
    curl_close($curl);
    if ($output === null or !property_exists($output, "data")) {
      return "<b>Fail to load message - may be authentication error</b>";
    }
    $entry_text = "";
    $entry_text = $entry_text . "<p>Author : {$output->data->user->screen_name}</p>";
    $entry_text = $entry_text . "<p>Created : {$output->data->created_at}</p>";
    $entry_text = $entry_text . "<p>Weibo source : {$output->data->source}</p>";
    $entry_text = $entry_text . "<p>" . $output->data->text . "</p>";
    foreach ($output->data->pics as $pic) {
      $entry_text = $entry_text . "<img src=\"{$pic->large->url}\" />";
    }
    return $entry_text;
  }
  public function extract_content_weixin(string $url)
  {
    // full url = https://mp.weixin.qq.com/s?__biz=MjM5OTYxMjc0MA==&mid=2652753480&idx=1&sn=0b489091ec61243a8803dfee7e738b70
    // short url = https://mp.weixin.qq.com/s/8DmyX-YDRZwONxbXEGCxbw
    $url_vars = parse_url($url);
    $site_url = $url_vars["scheme"] . $url_vars["host"];
    if(!array_key_exists("query", $url_vars)){
      $querystring = str_replace("/s/","", $url_vars["path"]);
    }else{
      $querystring = preg_replace("/(.*)&chksm.*/i", "$1", $url_vars["query"]);
    }
    $api_url = "https://ustc.fun/rss/ext-route/wechat/{$querystring}/?key=";
    $tmp = UrlHelper::fetch([
      "url" => $api_url
    ]);

    // Instantiate XML element
    $tmpdoc = new DOMDocument("1.0", "UTF-8");
    if (!@$tmpdoc->loadXML($tmp))
      return false;
    $entry = $tmpdoc->getElementsByTagName('item')->item(0);

    $item = json_decode('{"title":"", "description":"", "author":"", "pubDate":"", "link":""}');
    foreach ($entry->childNodes as $child) {
      if ($child->nodeName === "title") {
        $item->title = $child->textContent;
      } elseif ($child->nodeName === "description") {
        $item->description = $child->textContent;
      } elseif ($child->nodeName === "author") {
        $item->author = $child->textContent;
      } elseif ($child->nodeName === "pubDate") {
        $item->pubDate = $child->textContent;
      } elseif ($child->nodeName === "link") {
        $item->link = $child->textContent;
      }
    }
    return '<p>Author : ' . $item->author . '</p><p> Date : ' . $item->pubDate . '</p><hr>' . $item->description;
  }
  public function extract_content_twitter(string $url)
  {
    $url_vars = parse_url($url);
    // TODO: by RSSHub ext-route?
    // Replace host to http
    $site_url = "https://nitter.net";
    $api_url = $site_url . $url_vars["path"];
    
    $htmlstring = UrlHelper::fetch([
      "url" => $api_url,
      "http_accept" => "text/*",
      "type" => "text/html"
    ]);
    
    $tmpdoc = new DOMDocument("1.0", "UTF-8");
    if (!@$tmpdoc->loadHTML(mb_convert_encoding($htmlstring, 'HTML-ENTITIES', 'UTF-8')))
        return false;

    $html_main = $tmpdoc->getElementById('m');
    $tweet_xpath = new DOMXPath($html_main->ownerDocument);

    $tweet_entry = json_decode('{}');

    function query_node(string $querystring, DOMXPath $xpath) : DOMNode{
      return $xpath->query($querystring)[0];
    }
    // User - div.fullname + username
    $querystring = './/*[@id="m"]//a[contains(concat(" ",normalize-space(@class)," ")," fullname ")]';
    $tweet_entry->fullname = $tweet_xpath->query($querystring)[0]->textContent;
    $querystring = './/*[@id="m"]//a[contains(concat(" ",normalize-space(@class)," ")," username ")]';
    $tweet_entry->username = $tweet_xpath->query($querystring)[0]->textContent;
      // Avatar - img.avatar
    $avatar_url  = $site_url . query_node('.//*[@id="m"]//img[contains(concat(" ",normalize-space(@class)," ")," avatar ")]', $tweet_xpath)->getAttribute("src");
    // Date - span.tweet-date a
    $querystring = './/*[@id="m"]//span[contains(concat(" ",normalize-space(@class)," ")," tweet-date ")]//a';
    $node_tmp = $tweet_xpath->query($querystring)[0];
    $tweet_entry->url = $site_url . $node_tmp->getAttribute("href");
    $tweet_entry->timestamp = $node_tmp->getAttribute("title");
    // Text - div.tweet-content
    $node_tmp = query_node('.//*[@id="m"]//div[contains(concat(" ",normalize-space(@class)," ")," tweet-content ")]', $tweet_xpath);
    $tweet_entry->text = $node_tmp->ownerDocument->saveHTML($node_tmp);
    
    // Stats - span.tweet-stat
    $querystring = './/*[@id="m"]//span[contains(concat(" ",normalize-space(@class)," ")," tweet-stat ")]';
    $statstring = "";
    foreach($tweet_xpath->query($querystring) as $child){
      $node_stat = $child->getElementsByTagName("span")[0];
      $stats_type = str_replace("icon-", "", $node_stat->getAttribute("class"));
      $statstring = $statstring . " | {$stats_type} : {$child->textContent} | ";
    }

    // Contents
    $entry_content = "<img src=\"{$avatar_url}\" style=\"max-width:50px; float:left;\"/>"
      . "<p><a href=\"{$url}\">Twitter</a> - {$tweet_entry->fullname}{$tweet_entry->username}</p>"
      . "<p>{$tweet_entry->timestamp}, cached via <a href=\"{$api_url}\">Nitter</a></p>"
      . "<p>{$statstring}</p>"
      . "<hr><p>{$tweet_entry->text}</p>"
      . "<hr>";
    
    // Images - div.attachment.image a.still-image
    $querystring = './/*[@id="m"]//a[contains(concat(" ",normalize-space(@class)," ")," still-image ")]';
    foreach($tweet_xpath->query($querystring) as $node_img){
      $img_url = $site_url . $node_img->getAttribute("href");
      $entry_content = $entry_content . "<img src=\"{$img_url}\" alt />";
    }

    // Video
    $querystring = './/*[@id="m"]//video';
    foreach($tweet_xpath->query($querystring) as $node_video){
      $video_poster = $site_url . $node_video->getAttribute("poster");
      $video_url = $site_url . $node_video->getAttribute("data-url");
      $entry_content = $entry_content . "<video poster=\"{$video_poster}\" data-url=\"{$video_url}\" data-autoload=\"false\"></video>";
    }

    return $entry_content;
  }
  /**
   * @param string $url
   * @return string|false
   */
  public function extract_content(string $url)
  {
    if (str_contains($url, "weibo.com") || str_contains($url, "weibo.cn")) {
      return $this->extract_content_weibo($url);
    } else if (str_contains($url, "weixin.qq.com")) {
      return $this->extract_content_weixin($url);
    } else if (str_contains($url, "twitter.com") || str_contains($url, "nitter.net")) {
      return $this->extract_content_twitter($url);
    }

    $tmp = UrlHelper::fetch([
      "url" => $url,
      "http_accept" => "text/*",
      "type" => "text/html"
    ]);

    if ($tmp && mb_strlen($tmp) < 1024 * 500) {
      $tmpdoc = new DOMDocument("1.0", "UTF-8");

      if (!@$tmpdoc->loadHTML($tmp))
        return false;

      // this is the worst hack yet :(
      if (strtolower($tmpdoc->encoding) != 'utf-8') {
        $tmp = preg_replace("/<meta.*?charset.*?\/?>/i", "", $tmp);
        if (empty($tmpdoc->encoding)) {
          $tmp = mb_convert_encoding($tmp, 'utf-8');
        } else {
          $tmp = mb_convert_encoding($tmp, 'utf-8', $tmpdoc->encoding);
        }
      }

      try {

        $r = new Readability(new Configuration([
          'fixRelativeURLs' => true,
          'originalURL'     => $url,
        ]));

        if ($r->parse($tmp)) {

          $tmpxpath = new DOMXPath($r->getDOMDOcument());
          $entries = $tmpxpath->query('(//a[@href]|//img[@src])');

          foreach ($entries as $entry) {
            if ($entry->hasAttribute("href")) {
              $entry->setAttribute(
                "href",
                UrlHelper::rewrite_relative(UrlHelper::$fetch_effective_url, $entry->getAttribute("href"))
              );
            }

            if ($entry->hasAttribute("src")) {
              if ($entry->hasAttribute("data-src")) {
                $src = $entry->getAttribute("data-src");
              } else {
                $src = $entry->getAttribute("src");
              }
              if (!str_starts_with($src, "http")) {
                $entry->setAttribute(
                  "src",
                  UrlHelper::rewrite_relative(UrlHelper::$fetch_effective_url, $src)
                );
              } else {
                $entry->setAttribute("src", $src);
              }
            }
          }

          return $r->getContent();
        }
      } catch (Exception $e) {
        return false;
      }
    }

    return false;
  }

  /**
   * @param array<string, mixed> $article
   * @param bool $append_mode
   * @return array<string,mixed>
   * @throws PDOException
   */
  function process_article(array $article, bool $append_mode): array
  {

    $extracted_content = $this->extract_content($article["link"]);

    # let's see if there's anything of value in there
    $content_test = trim(strip_tags(Sanitizer::sanitize($extracted_content)));

    if ($content_test) {
      if ($append_mode)
        $article["content"] .= "<hr/>" . $extracted_content;
      else
        $article["content"] = $extracted_content;
    }

    return $article;
  }

  /**
   * @param string $name
   * @return array<int|string, mixed>
   * @throws PDOException
   * @deprecated
   */
  private function get_stored_array(string $name): array
  {
    return $this->host->get_array($this, $name);
  }

  function hook_article_filter($article)
  {

    $enabled_feeds = $this->get_stored_array("enabled_feeds");
    $append_feeds = $this->get_stored_array("append_feeds");

    $feed_id = $article["feed"]["id"];

    if (!in_array($feed_id, $enabled_feeds))
      return $article;

    return $this->process_article($article, in_array($feed_id, $append_feeds));
  }

  function hook_get_full_text($link)
  {
    $enable_share_anything = $this->host->get($this, "enable_share_anything");

    if ($enable_share_anything) {
      $extracted_content = $this->extract_content($link);

      # let's see if there's anything of value in there
      $content_test = trim(strip_tags(Sanitizer::sanitize($extracted_content)));

      if ($content_test) {
        return $extracted_content;
      }
    }

    return false;
  }

  function api_version()
  {
    return 2;
  }

  /**
   * @param array<int> $enabled_feeds
   * @return array<int>
   * @throws PDOException
   */
  private function filter_unknown_feeds(array $enabled_feeds): array
  {
    $tmp = array();

    foreach ($enabled_feeds as $feed) {

      $sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
      $sth->execute([$feed, $_SESSION['uid']]);

      if ($row = $sth->fetch()) {
        array_push($tmp, $feed);
      }
    }

    return $tmp;
  }

  function embed(): void
  {
    $article_id = (int) $_REQUEST["id"];

    $sth = $this->pdo->prepare("SELECT link FROM ttrss_entries WHERE id = ?");
    $sth->execute([$article_id]);

    $ret = [];

    if ($row = $sth->fetch()) {
      $url = $row["link"];

      if (str_contains($url, "weixin.qq.com")) {
        $ret["content"] = $this->extract_content($row["link"]);
      } else {
        $ret["content"] = Sanitizer::sanitize($this->extract_content($row["link"]));
      }
    }

    print json_encode($ret);
  }
}
