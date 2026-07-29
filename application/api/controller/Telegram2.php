<?php
namespace app\api\controller;

use app\common\controller\Api;
use think\Db;

class Telegram2 extends Api
{
    // 关闭登录及签名验证，允许 Telegram 访问
    protected $noNeedLogin = ['webhook'];
    protected $noNeedRight = ['webhook'];

    // 填入你的 Telegram Bot Token
    private $bot_token = '8989559753:AAGUwUd5yoNIAMFy-iZ0vcUtzSxJzHcjk1Q'; 

    public function webhook()
    {
        $content = file_get_contents("php://input");
        $update = json_decode($content, true);
        
        if (!$update || !isset($update['message'])) {
            $this->success(); // 必须向TG返回200状态
        }
        
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        
        // 1. 获取或初始化用户会话状态
        $session = Db::name('tg_session')->where('chat_id', $chat_id)->find();
        if (!$session) {
            Db::name('tg_session')->insert([
                'chat_id'    => $chat_id,
                'state'      => 'idle',
                'createtime' => time(),
                'updatetime' => time()
            ]);
            $session = Db::name('tg_session')->where('chat_id', $chat_id)->find();
        }
        
        $state = $session['state'];
        
        // 2. 状态机逻辑处理
        if ($state == 'idle') {
            // 第一步：接收转发的消息
            $text = $message['text'] ?? $message['caption'] ?? '';
            if (empty($text)) {
                $this->sendMsg($chat_id, "❌ 请转发一条包含文字或链接的消息给我。");
                $this->success();
            }
            
            Db::name('tg_session')->where('chat_id', $chat_id)->update([
                'state'        => 'wait_old_link',
                'original_msg' => json_encode($message, JSON_UNESCAPED_UNICODE),
                'updatetime'   => time()
            ]);
            
            $this->sendMsg($chat_id, "📥 <b>已收到转发内容！</b>\n\n👉 接下来请发送：<b>【要被替换的旧链接】</b>");
            
        } elseif ($state == 'wait_old_link') {
            // 第二步：接收旧链接
            $old_link = $message['text'] ?? '';
            if (empty($old_link) || !filter_var($old_link, FILTER_VALIDATE_URL)) {
                $this->sendMsg($chat_id, "⚠️ 看起来不是有效的链接，请重新输入要替换的旧链接：");
                $this->success();
            }
            
            Db::name('tg_session')->where('chat_id', $chat_id)->update([
                'state'      => 'wait_new_link',
                'old_link'   => $old_link,
                'updatetime' => time()
            ]);
            
            $this->sendMsg($chat_id, "✅ 已记录旧链接。\n\n👉 接下来请发送：<b>【替换后的新链接】</b>");
            
        } elseif ($state == 'wait_new_link') {
            // 第三步：接收新链接并开始处理
            $new_link = $message['text'] ?? '';
            if (empty($new_link) || !filter_var($new_link, FILTER_VALIDATE_URL)) {
                $this->sendMsg($chat_id, "⚠️ 看起来不是有效的链接，请重新输入替换后的新链接：");
                $this->success();
            }
            
            $original_msg = json_decode($session['original_msg'], true);
            $old_link     = $session['old_link'];
            
            // 执行核心渲染与替换
            $this->processAndReply($chat_id, $original_msg, $old_link, $new_link);
            
            // 重置状态
            Db::name('tg_session')->where('chat_id', $chat_id)->update([
                'state'        => 'idle',
                'original_msg' => null,
                'old_link'     => null,
                'updatetime'   => time()
            ]);
        }
        
        $this->success();
    }
    
    /**
     * 核心处理：将原始样式转为 HTML，替换链接，原样返回
     */
    /**
     * 核心处理：原生 Entities 偏移量修正算法，100% 无损还原样式和底部按钮
     */
    private function processAndReply($chat_id, $msg, $old_link, $new_link)
    {
        $is_caption = isset($msg['caption']);
        $text       = $is_caption ? $msg['caption'] : ($msg['text'] ?? '');
        $entities   = $is_caption ? ($msg['caption_entities'] ?? []) : ($msg['entities'] ?? []);
        
        // --- 第一部分：处理隐藏在文字下的超链接 (text_link) ---
        foreach ($entities as &$entity) {
            // 如果是像 "点击这里" 这种隐藏链接，且 URL 匹配，直接替换 URL，文本长度不变
            if ($entity['type'] === 'text_link' && $entity['url'] === $old_link) {
                $entity['url'] = $new_link;
            }
        }
        
        // --- 第二部分：处理明文链接替换 (需重新计算 UTF-16 偏移量) ---
        if (strpos($text, $old_link) !== false) {
            // Telegram 严格要求使用 UTF-16 编码来计算长度和偏移量
            $text_utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
            $old_utf16  = mb_convert_encoding($old_link, 'UTF-16BE', 'UTF-8');
            $new_utf16  = mb_convert_encoding($new_link, 'UTF-16BE', 'UTF-8');
            
            $old_len = strlen($old_utf16) / 2;
            $new_len = strlen($new_utf16) / 2;
            $diff    = $new_len - $old_len; // 计算新旧链接的长度差
            
            // 找到原文本中所有需要替换的位置
            $offset = 0;
            $positions = [];
            while (($pos = strpos($text_utf16, $old_utf16, $offset)) !== false) {
                $positions[] = $pos / 2; // 记录 UTF-16 字符级别的位置
                $offset = $pos + strlen($old_utf16);
            }
            
            // 执行纯文本替换
            $text = str_replace($old_link, $new_link, $text);
            
            // 修正所有底层样式的偏移量，确保不因为文本长度改变而错位
            foreach ($entities as &$entity) {
                $e_start = $entity['offset'];
                $e_end   = $entity['offset'] + $entity['length'];
                $shift_start  = 0;
                $shift_length = 0;
                
                foreach ($positions as $p) {
                    if ($p < $e_start) {
                        // 替换发生在样式前面，样式整体后移
                        $shift_start += $diff; 
                    } elseif ($p >= $e_start && $p < $e_end) {
                        // 替换发生在样式内部，样式的覆盖长度增加/减少
                        $shift_length += $diff; 
                    }
                }
                
                $entity['offset'] += $shift_start;
                $entity['length'] += $shift_length;
            }
        }

        // --- 第三部分：组装发送参数 ---
        $params = [
            'chat_id' => $chat_id,
        ];

        // 🔥 完美保留底部按钮 (Reply Markup)
        if (isset($msg['reply_markup'])) {
            $reply_markup_json = json_encode($msg['reply_markup'], JSON_UNESCAPED_UNICODE);
            // 如果底部按钮的动作链接里也包含了旧链接，顺便一起替换掉
            $reply_markup_json = str_replace($old_link, $new_link, $reply_markup_json);
            $params['reply_markup'] = $reply_markup_json;
        }

        // 发送回 Telegram (不使用 parse_mode，直接发送原生的 entities)
        if (isset($msg['photo'])) {
            $photo = end($msg['photo']); // 取最大清晰度
            $params['photo']   = $photo['file_id'];
            $params['caption'] = $text;
            $params['caption_entities'] = json_encode($entities);
            $this->apiCall('sendPhoto', $params);
        } elseif (isset($msg['video'])) {
            $params['video']   = $msg['video']['file_id'];
            $params['caption'] = $text;
            $params['caption_entities'] = json_encode($entities);
            $this->apiCall('sendVideo', $params);
        } else {
            $params['text']     = $text;
            $params['entities'] = json_encode($entities);
            $this->apiCall('sendMessage', $params);
        }
    }
    
    /**
     * 高级算法：将 Telegram Entities 倒序切片组合为无损 HTML
     */
    private function entitiesToHtml($text, $entities)
    {
        if (empty($entities)) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        
        // 按 offset 降序排列 (从后往前处理，确保前方的偏移量在切割时不失效)
        usort($entities, function($a, $b) {
            return $b['offset'] <=> $a['offset'];
        });
        
        // 必须转为 UTF-16BE 字节流来精准对齐 Telegram 的 offset
        $utf16       = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $last_offset = strlen($utf16) / 2;
        $html_out    = '';
        
        foreach ($entities as $entity) {
            $offset = $entity['offset'];
            $length = $entity['length'];
            
            // 截取实体后面的普通文本
            if ($last_offset > ($offset + $length)) {
                $tail_utf16 = substr($utf16, ($offset + $length) * 2, ($last_offset - ($offset + $length)) * 2);
                $tail_text  = mb_convert_encoding($tail_utf16, 'UTF-8', 'UTF-16BE');
                $html_out   = htmlspecialchars($tail_text, ENT_QUOTES, 'UTF-8') . $html_out;
            }
            
            // 截取实体内部的文本
            $entity_utf16        = substr($utf16, $offset * 2, $length * 2);
            $entity_text         = mb_convert_encoding($entity_utf16, 'UTF-8', 'UTF-16BE');
            $entity_text_escaped = htmlspecialchars($entity_text, ENT_QUOTES, 'UTF-8');
            
            // 样式包裹
            $html_piece = '';
            switch ($entity['type']) {
                case 'bold':          $html_piece = "<b>{$entity_text_escaped}</b>"; break;
                case 'italic':        $html_piece = "<i>{$entity_text_escaped}</i>"; break;
                case 'underline':     $html_piece = "<u>{$entity_text_escaped}</u>"; break;
                case 'strikethrough': $html_piece = "<s>{$entity_text_escaped}</s>"; break;
                case 'code':          $html_piece = "<code>{$entity_text_escaped}</code>"; break;
                case 'pre':           $html_piece = "<pre>{$entity_text_escaped}</pre>"; break;
                case 'text_link': 
                    $url = htmlspecialchars($entity['url'], ENT_QUOTES, 'UTF-8');
                    $html_piece = "<a href=\"{$url}\">{$entity_text_escaped}</a>"; 
                    break;
                default:              $html_piece = $entity_text_escaped; break;
            }
            
            $html_out    = $html_piece . $html_out;
            $last_offset = $offset;
        }
        
        // 截取最前面的普通文本
        if ($last_offset > 0) {
            $head_utf16 = substr($utf16, 0, $last_offset * 2);
            $head_text  = mb_convert_encoding($head_utf16, 'UTF-8', 'UTF-16BE');
            $html_out   = htmlspecialchars($head_text, ENT_QUOTES, 'UTF-8') . $html_out;
        }
        
        return $html_out;
    }
    
    private function sendMsg($chat_id, $text, $parse_mode = 'HTML')
    {
        $this->apiCall('sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => $parse_mode
        ]);
    }
    
    private function apiCall($method, $params)
    {
        $url = "https://api.telegram.org/bot{$this->bot_token}/{$method}";
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }
}