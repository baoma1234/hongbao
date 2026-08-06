define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var current = null;
    var tableInited = false;
    var stickerLoaded = false;
    var stickerItems = [];
    var knownMaxId = 0;
    var knownConvMap = {};
    var pollTimer = null;
    var pollBusy = false;
    var ws = null;
    var wsConnected = false;
    var wsConnecting = false;
    var wsReconnectTimer = null;
    var wsPingTimer = null;
    var wsAudioCtx = null;
    var lastConversations = [];
    var refreshTimer = null;
    var titleFlashTimer = null;
    var titleBase = '';
    var audioUnlocked = false;
    var notifyAudio = null;
    var toastedKeys = {};
    var EMOJIS = ['😀','😁','😂','🤣','😊','😍','😘','😜','🤔','🙄','😴','😭','😤','😱','👍','👎','👏','🙏','🔥','❤️','💔','🎉','🧧','💰','✅','❌','⭐','🌟','💯','🤝'];

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtTime(ts) {
        ts = parseInt(ts, 10) || 0;
        if (!ts) return '';
        var d = new Date(ts * 1000);
        var p = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function convKey(it) {
        return String(it.conversation_type) + ':' + String(it.conversation_id);
    }

    function previewLabel(it) {
        var t = parseInt(it.last_msg_type, 10) || 0;
        var c = String(it.last_content || '');
        if (t === 2) return '[红包] ' + c;
        if (t === 3) return c || '[系统]';
        if (t === 4) return '[图片]';
        if (t === 5) return '[视频]';
        if (t === 6) return '[表情]';
        if (t === 7) return '[文件] ' + c;
        return c || '(新消息)';
    }

    function ensureToastStack() {
        var box = document.getElementById('imToastStack');
        if (!box) {
            box = document.createElement('div');
            box.id = 'imToastStack';
            box.className = 'im-toast-stack';
            document.body.appendChild(box);
        }
        return box;
    }

    function unlockAudio() {
        if (audioUnlocked) return;
        audioUnlocked = true;
        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                if (!wsAudioCtx) wsAudioCtx = new AudioContext();
                if (wsAudioCtx.state === 'suspended' && wsAudioCtx.resume) {
                    wsAudioCtx.resume().catch(function () {});
                }
            }
        } catch (e) {}
        try {
            if (!notifyAudio) {
                // 短促叮声（WAV data URI），不依赖外部 mp3
                notifyAudio = new Audio(
                    'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABAAZGF0YQAAAAA='
                );
            }
            notifyAudio.volume = 0.01;
            var p = notifyAudio.play();
            if (p && p.then) p.then(function () {
                notifyAudio.pause();
                notifyAudio.currentTime = 0;
                notifyAudio.volume = 0.85;
            }).catch(function () {});
        } catch (e2) {}
    }

    function playBeepOnce(freq, startAt, dur, vol) {
        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            if (!wsAudioCtx) wsAudioCtx = new AudioContext();
            var ctx = wsAudioCtx;
            if (ctx.state === 'suspended' && ctx.resume) ctx.resume().catch(function () {});
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            var t0 = ctx.currentTime + (startAt || 0);
            gain.gain.setValueAtTime(0.0001, t0);
            gain.gain.exponentialRampToValueAtTime(vol || 0.22, t0 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t0 + (dur || 0.18));
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(t0);
            osc.stop(t0 + (dur || 0.18) + 0.02);
        } catch (e) {}
    }

    function playBeep() {
        unlockAudio();
        // 两声提示，比原来的轻 beep 更明显
        playBeepOnce(880, 0, 0.16, 0.25);
        playBeepOnce(1175, 0.2, 0.18, 0.22);
        try {
            if (notifyAudio) {
                notifyAudio.currentTime = 0;
                var p = notifyAudio.play();
                if (p && p.catch) p.catch(function () {});
            }
        } catch (e) {}
    }

    function flashDocumentTitle(title) {
        try {
            if (!titleBase) titleBase = document.title || 'IM代聊';
            if (titleFlashTimer) clearInterval(titleFlashTimer);
            var on = false;
            var tip = '【新消息】' + (title || '');
            titleFlashTimer = setInterval(function () {
                on = !on;
                document.title = on ? tip : titleBase;
            }, 900);
            setTimeout(function () {
                if (titleFlashTimer) clearInterval(titleFlashTimer);
                titleFlashTimer = null;
                document.title = titleBase;
            }, 12000);
        } catch (e) {}
    }

    function desktopNotify(it) {
        try {
            if (typeof Notification === 'undefined') return;
            if (Notification.permission === 'default') {
                Notification.requestPermission().catch(function () {});
            }
            if (Notification.permission !== 'granted') return;
            // 仅页签不可见时弹系统通知，避免打扰正在操作
            if (!document.hidden) return;
            var n = new Notification(it.title || 'IM新消息', {
                body: previewLabel(it),
                tag: 'imagent-' + String(it.conversation_type) + '-' + String(it.conversation_id),
                renotify: true
            });
            n.onclick = function () {
                try { window.focus(); } catch (e) {}
                n.close();
            };
            setTimeout(function () { try { n.close(); } catch (e) {} }, 8000);
        } catch (e) {}
    }

    function toastDedupeKey(it) {
        return String(it.conversation_type || '') + ':' + String(it.conversation_id || '') + ':' + String(it.last_id || it.updatetime || '');
    }

    function showImToast(it) {
        var key = toastDedupeKey(it);
        if (key && toastedKeys[key]) return;
        if (key) {
            toastedKeys[key] = 1;
            // 防止 map 无限增长
            var keys = Object.keys(toastedKeys);
            if (keys.length > 200) {
                keys.slice(0, keys.length - 120).forEach(function (k) { delete toastedKeys[k]; });
            }
        }
        var box = ensureToastStack();
        var el = document.createElement('div');
        el.className = 'im-toast';
        el.innerHTML =
            '<div class="im-toast-title">' + esc(it.title || '新消息') + '</div>' +
            '<div class="im-toast-body">' + esc(previewLabel(it)) + '</div>' +
            '<div class="im-toast-time">' + esc(fmtTime(it.updatetime)) + '</div>';
        el.onclick = function () {
            openRoom({
                conversation_type: parseInt(it.conversation_type, 10) || 1,
                conversation_id: String(it.conversation_id || ''),
                group_id: parseInt(it.group_id, 10) || 0,
                peer_a: parseInt(it.peer_a, 10) || 0,
                peer_b: parseInt(it.peer_b, 10) || 0,
                title: it.title || ''
            });
            $('#panelChat').addClass('show');
            $('#panelAll').removeClass('show');
            $('#btnViewChat').removeClass('btn-default').addClass('btn-primary');
            $('#btnViewAll').removeClass('btn-primary').addClass('btn-default');
            if (el.parentNode) el.parentNode.removeChild(el);
        };
        playBeep();
        flashDocumentTitle(it.title || '新消息');
        desktopNotify(it);
        box.appendChild(el);
        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 10000);
        while (box.children.length > 5) {
            box.removeChild(box.firstChild);
        }
    }

    function token() {
        try { return localStorage.getItem('fans_hub_token') || ''; } catch (e) { return ''; }
    }

    function defaultWsUrl() {
        try {
            if (window.CONFIG && CONFIG.IM_WS_URL) return String(CONFIG.IM_WS_URL);
        } catch (e) {}
        var host = location.hostname || '127.0.0.1';
        var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        return proto + '//' + host + ':17272';
    }

    function scheduleRefresh() {
        if (refreshTimer) clearTimeout(refreshTimer);
        refreshTimer = setTimeout(function () {
            refreshTimer = null;
            if (current) loadHistory();
            loadConversations();
        }, 260);
    }

    function scheduleRefreshList() {
        if (refreshTimer) clearTimeout(refreshTimer);
        refreshTimer = setTimeout(function () {
            refreshTimer = null;
            loadConversations();
        }, 260);
    }

    function onWsIncomingMessage(msg) {
        if (!msg) return;
        var type = parseInt(msg.conversation_type, 10) || 1;
        var cid = type === 2 ? (msg.group_id || msg.conversation_id) : msg.conversation_id;
        if (!cid) return;

        var key = String(type) + ':' + String(cid);
        var msgDbId = parseInt(msg.id, 10) || 0;
        var prev = knownConvMap[key] | 0;
        if (msgDbId && prev && msgDbId <= prev) return;
        if (msgDbId) knownConvMap[key] = Math.max(prev, msgDbId);

        var viewing = current
            && (parseInt(current.conversation_type, 10) || 1) === type
            && String(current.conversation_id) === String(cid);

        if (viewing) {
            scheduleRefresh();
            return;
        }

        var base = null;
        for (var i = 0; i < lastConversations.length; i++) {
            var it = lastConversations[i];
            if (String(it.conversation_type) === String(type) && String(it.conversation_id) === String(cid)) {
                base = it;
                break;
            }
        }
        var title = base ? base.title : (type === 2 ? ('群聊 #' + cid) : ('私聊 · ID ' + (msg.from_user_id || '')));

        showImToast({
            title: title,
            conversation_type: type,
            conversation_id: String(cid),
            group_id: type === 2 ? (parseInt(msg.group_id, 10) || 0) : 0,
            peer_a: type === 1 ? (parseInt(msg.from_user_id, 10) || 0) : 0,
            peer_b: type === 1 ? (parseInt(msg.to_user_id, 10) || 0) : 0,
            last_msg_type: parseInt(msg.msg_type, 10) || 1,
            last_content: String(msg.content || ''),
            last_id: msgDbId,
            updatetime: parseInt(msg.createtime, 10) || 0
        });
        scheduleRefreshList();
    }

    function handleWsPacket(packet) {
        if (!packet || !packet.type) return;
        switch (packet.type) {
            case 'auth.ok':
                wsConnected = true;
                wsConnecting = false;
                loadConversations();
                if (current) loadHistory();
                startWsPing();
                break;
            case 'private.message':
            case 'group.message':
                if (packet.data && packet.data.message) onWsIncomingMessage(packet.data.message);
                break;
            case 'message.recalled':
                if (packet.data && packet.data.message) {
                    var m = packet.data.message;
                    var type = parseInt(m.conversation_type, 10) || 1;
                    var cid = type === 2 ? (m.group_id || m.conversation_id) : m.conversation_id;
                    var viewing = current
                        && (parseInt(current.conversation_type, 10) || 1) === type
                        && String(current.conversation_id) === String(cid);
                    if (viewing) scheduleRefresh();
                    else scheduleRefreshList();
                }
                break;
            default:
                break;
        }
    }

    function startWsPing() {
        stopWsPing();
        wsPingTimer = setInterval(function () {
            try {
                if (ws && wsConnected && ws.readyState === 1) {
                    ws.send(JSON.stringify({ type: 'ping', data: {}, req_id: 'ping' }));
                }
            } catch (e) {}
        }, 25000);
    }

    function stopWsPing() {
        if (wsPingTimer) {
            clearInterval(wsPingTimer);
            wsPingTimer = null;
        }
    }

    function connectWs(force) {
        if (wsConnected && !force) return;
        if (wsConnecting) return;
        var t = token();
        if (!t) return; // 没有 token 就保持轮询

        wsConnecting = true;
        wsConnected = false;
        try {
            if (ws) {
                try { ws.onclose = null; ws.close(); } catch (e) {}
                ws = null;
            }
        } catch (e) {}

        var url = defaultWsUrl();
        try {
            ws = new WebSocket(url);
        } catch (e) {
            wsConnecting = false;
            return;
        }

        ws.onopen = function () {
            try {
                ws.send(JSON.stringify({ type: 'auth', data: { token: t }, req_id: 'auth' }));
            } catch (e) {}
        };
        ws.onmessage = function (ev) {
            var packet;
            try { packet = JSON.parse(ev.data); } catch (e) { return; }
            handleWsPacket(packet);
        };
        ws.onclose = function () {
            wsConnected = false;
            wsConnecting = false;
            stopWsPing();
            if (wsReconnectTimer) clearTimeout(wsReconnectTimer);
            wsReconnectTimer = setTimeout(function () {
                wsReconnectTimer = null;
                connectWs(true);
            }, 3000);
        };
        ws.onerror = function () {
            wsConnected = false;
        };
    }

    function detectNewMessages(list) {
        // WS 通时以 WS 推送为主；轮询仍作兜底（toastedKeys 去重）
        var maxId = 0;
        var news = [];
        (list || []).forEach(function (it) {
            var id = parseInt(it.last_id, 10) || 0;
            if (id > maxId) maxId = id;
            var key = convKey(it);
            var prev = knownConvMap[key] | 0;
            if (knownMaxId > 0 && id > prev) {
                var viewing = current
                    && String(current.conversation_id) === String(it.conversation_id)
                    && String(current.conversation_type) === String(it.conversation_type);
                if (viewing) {
                    if (!wsConnected) loadHistory();
                } else {
                    news.push(it);
                }
            }
            knownConvMap[key] = Math.max(prev, id);
        });
        if (knownMaxId === 0) {
            knownMaxId = maxId;
            return;
        }
        if (news.length) {
            news.sort(function (a, b) {
                return (parseInt(b.last_id, 10) || 0) - (parseInt(a.last_id, 10) || 0);
            });
            news.slice(0, 3).forEach(showImToast);
        }
        if (maxId > knownMaxId) knownMaxId = maxId;
    }

    function parseExtra(m) {
        var extra = m && m.extra;
        if (typeof extra === 'string' && extra) {
            try { extra = JSON.parse(extra); } catch (e) { extra = {}; }
        }
        return extra && typeof extra === 'object' ? extra : {};
    }

    function mediaUrl(extra) {
        if (!extra) return '';
        var u = String(extra.url || extra.fullurl || '');
        if (String(extra.url || '').indexOf('/888/stickers/') === 0) {
            u = String(extra.url);
        } else if (u.indexOf('/999/static/stickers/') === 0) {
            u = '/888/stickers/' + u.slice('/999/static/stickers/'.length);
        }
        if (!u) u = String(extra.fullurl || extra.url || '');
        if (!u) return '';
        // 编码中文路径段
        try {
            u = u.split('/').map(function (seg, i) {
                if (i === 0 || seg === '') return seg;
                try { return encodeURIComponent(decodeURIComponent(seg)); } catch (e) { return encodeURIComponent(seg); }
            }).join('/');
        } catch (e2) {}
        if (/^https?:\/\//i.test(u) || u.charAt(0) === '/') return u;
        return '/' + u.replace(/^\.\//, '');
    }

    function closePanels() {
        $('#panelEmoji,#panelSticker,#panelRedpacket').removeClass('open');
        $('#btnEmoji,#btnSticker,#btnRedpacket').removeClass('active');
    }

    function togglePanel(id, btn) {
        var $p = $(id);
        var open = !$p.hasClass('open');
        closePanels();
        if (open) {
            $p.addClass('open');
            $(btn).addClass('active');
        }
    }

    function renderMsgBody(m) {
        var type = parseInt(m.msg_type, 10) || 1;
        var status = parseInt(m.status, 10) || 1;
        var extra = parseExtra(m);
        if (status === 2) {
            return '<div class="im-sys">已撤回</div>';
        }
        if (type === 3) {
            return '<div class="im-sys">' + esc(m.content || '') + '</div>';
        }
        if (type === 2) {
            var bless = esc(extra.blessing || m.content || '恭喜发财');
            return '<div class="im-rp-card"><div class="rp-title">🧧 红包</div><div class="rp-sub">' + bless + '</div></div>';
        }
        if (type === 4) {
            var img = mediaUrl(extra);
            if (img) {
                return '<img class="im-media-img" src="' + esc(img) + '" alt="图片" data-preview="' + esc(img) + '" data-preview-type="image">';
            }
            return esc(m.content || '[图片]');
        }
        if (type === 5) {
            var vid = mediaUrl(extra);
            if (vid) {
                return '<video class="im-media-video" src="' + esc(vid) + '" controls playsinline data-preview="' + esc(vid) + '" data-preview-type="video"></video>';
            }
            return esc(m.content || '[视频]');
        }
        if (type === 6) {
            var st = mediaUrl(extra);
            if (st) {
                return '<img class="im-sticker-img" src="' + esc(st) + '" alt="' + esc(extra.code || '表情') + '" data-preview="' + esc(st) + '" data-preview-type="image">';
            }
            return esc(m.content || '[表情]');
        }
        if (type === 7) {
            var fu = mediaUrl(extra);
            var fname = extra.name || '文件';
            if (fu) {
                return '<a class="im-file-card" href="' + esc(fu) + '" target="_blank" rel="noopener">📎 ' + esc(fname) + '</a>';
            }
            return esc(m.content || '[文件]');
        }
        return esc(m.content || '');
    }

    function loadConversations(opts) {
        opts = opts || {};
        if (opts.poll && pollBusy) return;
        if (opts.poll) pollBusy = true;
        var q = $('#convSearch').val() || '';
        Backend.api.ajax({
            url: 'fanshub/imagent/conversations',
            data: { q: q, limit: 100 },
            loading: opts.poll ? false : undefined
        }, function (data) {
            var list = (data && data.list) || [];
            lastConversations = list;
            detectNewMessages(list);
            var box = $('#convList');
            if (!list.length) {
                box.html('<div class="im-empty">暂无会话</div>');
                return false;
            }
            var html = list.map(function (it) {
                var active = current && String(current.conversation_id) === String(it.conversation_id) && current.conversation_type == it.conversation_type ? ' active' : '';
                return '<button type="button" class="im-conv-item' + active + '"' +
                    ' data-type="' + it.conversation_type + '"' +
                    ' data-cid="' + esc(it.conversation_id) + '"' +
                    ' data-gid="' + (it.group_id || 0) + '"' +
                    ' data-a="' + (it.peer_a || 0) + '"' +
                    ' data-b="' + (it.peer_b || 0) + '"' +
                    ' data-title="' + esc(it.title) + '">' +
                    '<div class="im-conv-title">' + esc(it.title) + '</div>' +
                    '<div class="im-conv-preview">' + esc(it.last_content || '') + '</div>' +
                    '<div class="im-conv-time">' + esc(fmtTime(it.updatetime)) + '</div>' +
                    '</button>';
            }).join('');
            box.html(html);
            return false;
        }, function () {
            return false;
        }).always(function () {
            if (opts.poll) pollBusy = false;
        });
    }

    function openRoom(meta) {
        current = meta;
        $('#roomTitle').text(meta.title || '会话');
        $('#convList .im-conv-item').removeClass('active');
        $('#convList .im-conv-item').filter(function () {
            return String($(this).attr('data-cid')) === String(meta.conversation_id)
                && String($(this).attr('data-type')) === String(meta.conversation_type);
        }).addClass('active');
        if (meta.conversation_type == 1) {
            $('#rpCountWrap').hide();
            $('#rpCount').val(1);
        } else {
            $('#rpCountWrap').show();
        }
        closePanels();
        loadHistory();
    }

    function loadHistory() {
        if (!current) return;
        Backend.api.ajax({
            url: 'fanshub/imagent/history',
            data: {
                conversation_type: current.conversation_type,
                conversation_id: current.conversation_id,
                include_deleted: $('#chkIncludeDeleted').is(':checked') ? 1 : 0,
                limit: 80
            }
        }, function (data) {
            var list = (data && data.list) || [];
            var box = $('#msgBox');
            if (!list.length) {
                box.html('<div class="im-empty">暂无消息</div>');
                return false;
            }
            var agentId = parseInt($('#agentSelect').val(), 10) || 0;
            var html = list.map(function (m) {
                var type = parseInt(m.msg_type, 10) || 1;
                var recalled = m.status == 2;
                if (recalled) {
                    return '<div class="im-row system" data-id="' + m.id + '"><div class="im-sys">#' + m.id + ' 已撤回 · ' + esc(m.from_label || '') + '</div></div>';
                }
                if (type === 3) {
                    return '<div class="im-row system"><div class="im-sys">' + esc(m.content || '') + '</div></div>';
                }
                var mine = agentId > 0 && m.from_user_id == agentId;
                var deleted = m.status == 3;
                var bubbleClass = 'im-bubble';
                if (type === 4 || type === 5 || type === 7) bubbleClass += ' media';
                if (type === 6) bubbleClass += ' sticker';
                var ops = '<div class="im-ops">' +
                    '<a href="javascript:;" class="btn btn-link btn-xs btn-edit-msg" data-id="' + m.id + '">编辑</a>' +
                    (!deleted ? '<a href="javascript:;" class="btn btn-link btn-xs btn-recall-msg" data-id="' + m.id + '">撤回</a>' : '') +
                    (deleted
                        ? '<a href="javascript:;" class="btn btn-link btn-xs btn-restore-msg" data-id="' + m.id + '">恢复</a>'
                        : '<a href="javascript:;" class="btn btn-link btn-xs btn-del-msg" data-id="' + m.id + '">删除</a>') +
                    '</div>';
                return '<div class="im-row' + (mine ? ' me' : '') + (deleted ? ' im-deleted' : '') + '" data-id="' + m.id + '">' +
                    '<div class="' + bubbleClass + '">' +
                    '<div style="font-size:11px;opacity:.8;margin-bottom:4px;">' + esc(m.from_label || ('ID' + m.from_user_id)) + '</div>' +
                    renderMsgBody(m) +
                    '<span class="im-meta">#' + m.id + ' · ' + esc(m.createtime_text || fmtTime(m.createtime)) + (deleted ? ' · 已删' : '') + '</span>' +
                    ops +
                    '</div></div>';
            }).join('');
            box.html(html);
            box.scrollTop(box[0].scrollHeight);
            return false;
        });
    }

    function resolvePeerPayload(base) {
        var agentUserId = parseInt($('#agentSelect').val(), 10) || 0;
        if (!current) {
            Layer.msg('请先选择会话');
            return null;
        }
        if (!agentUserId) {
            Layer.msg('请先添加托管账号');
            return null;
        }
        var payload = $.extend({
            conversation_type: current.conversation_type,
            conversation_id: current.conversation_id,
            agent_user_id: agentUserId
        }, base || {});
        if (current.conversation_type == 2) {
            payload.group_id = current.group_id || current.conversation_id;
        } else {
            var a = parseInt(current.peer_a, 10) || 0;
            var b = parseInt(current.peer_b, 10) || 0;
            payload.to_user_id = (a === agentUserId) ? b : ((b === agentUserId) ? a : b);
        }
        return payload;
    }

    function sendMsg() {
        var content = $.trim($('#msgInput').val() || '');
        if (!content) {
            Layer.msg('请输入内容');
            return;
        }
        var payload = resolvePeerPayload({ content: content, msg_type: 1 });
        if (!payload) return;
        Backend.api.ajax({
            url: 'fanshub/imagent/send',
            data: payload
        }, function () {
            $('#msgInput').val('');
            closePanels();
            loadHistory();
            loadConversations();
            return false;
        });
    }

    function sendRich(msgType, content, extra) {
        var payload = resolvePeerPayload({
            content: content || '',
            msg_type: msgType,
            extra: JSON.stringify(extra || {})
        });
        if (!payload) return;
        Backend.api.ajax({
            url: 'fanshub/imagent/send',
            data: payload
        }, function () {
            closePanels();
            loadHistory();
            loadConversations();
            return false;
        });
    }

    function uploadAndSend(file, msgType) {
        if (!file) return;
        if (!current) {
            Layer.msg('请先选择会话');
            return;
        }
        var fd = new FormData();
        fd.append('file', file);
        var idx = Layer.load(1);
        $.ajax({
            url: Fast.api.fixurl('ajax/upload'),
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (ret) {
                Layer.close(idx);
                if (!ret || ret.code !== 1 || !ret.data) {
                    Layer.msg((ret && ret.msg) || '上传失败');
                    return;
                }
                var url = ret.data.url || '';
                var fullurl = ret.data.fullurl || url;
                if (url && url.charAt(0) !== '/' && !/^https?:/i.test(url)) {
                    url = '/' + url;
                }
                var name = file.name || '';
                var ext = '';
                var dot = name.lastIndexOf('.');
                if (dot >= 0) ext = name.slice(dot + 1).toLowerCase();
                var label = '[图片]';
                if (msgType === 5) label = '[视频]';
                if (msgType === 7) label = '[文件]' + name;
                sendRich(msgType, label, { url: url, fullurl: fullurl, name: name, size: file.size || 0, ext: ext });
            },
            error: function () {
                Layer.close(idx);
                Layer.msg('上传失败');
            }
        });
    }

    function buildEmojiPanel() {
        var html = '<div class="im-emoji-grid">' + EMOJIS.map(function (e) {
            return '<button type="button" class="im-emoji-item" data-emoji="' + e + '">' + e + '</button>';
        }).join('') + '</div>';
        $('#panelEmoji').html(html);
    }

    function ensureStickers() {
        if (stickerLoaded) {
            renderStickerPanel();
            return;
        }
        $.getJSON('/888/data/stickers.json').done(function (data) {
            stickerItems = [];
            var packs = (data && data.packs) || [];
            packs.forEach(function (pack) {
                (pack.categories || []).forEach(function (cat) {
                    (cat.items || []).forEach(function (it) {
                        var url = it.url || '';
                        if (url && url.charAt(0) !== '/' && !/^https?:/i.test(url)) {
                            url = '/888/' + url.replace(/^\.\//, '');
                        }
                        stickerItems.push({
                            code: it.code || '',
                            url: url,
                            pack: pack.id || 'wechat'
                        });
                    });
                });
            });
            stickerLoaded = true;
            renderStickerPanel();
        }).fail(function () {
            $('#panelSticker').html('<div class="im-empty">表情包加载失败</div>');
        });
    }

    function renderStickerPanel() {
        if (!stickerItems.length) {
            $('#panelSticker').html('<div class="im-empty">暂无表情包</div>');
            return;
        }
        var html = '<div class="im-sticker-grid">' + stickerItems.slice(0, 120).map(function (it) {
            var url = it.url || '';
            if (url && url.charAt(0) !== '/' && !/^https?:/i.test(url)) {
                url = '/888/' + url.replace(/^\.\//, '');
            }
            return '<button type="button" class="im-sticker-item" data-code="' + esc(it.code) + '" data-url="' + esc(url) + '" data-pack="' + esc(it.pack || 'wechat') + '" title="' + esc(it.code) + '">' +
                '<img src="' + esc(url) + '" alt="' + esc(it.code) + '">' +
                '</button>';
        }).join('') + '</div>';
        $('#panelSticker').html(html);
    }

    function openLightbox(src, type) {
        if (!src) return;
        var body = type === 'video'
            ? '<video src="' + esc(src) + '" controls autoplay playsinline></video>'
            : '<img src="' + esc(src) + '" alt="preview">';
        var $box = $('<div class="im-lightbox"><button type="button" class="im-lightbox-close">×</button>' + body + '</div>');
        $box.on('click', function (e) {
            if (e.target === this || $(e.target).hasClass('im-lightbox-close')) {
                $box.remove();
            }
        });
        $('body').append($box);
    }

    function initTable() {
        if (tableInited) {
            $('#table').bootstrapTable('refresh');
            return;
        }
        tableInited = true;
        Table.api.init({
            extend: {
                index_url: 'fanshub/imagent/messages',
                edit_url: 'fanshub/imagent/edit',
                del_url: 'fanshub/imagent/del',
                table: 'chat_messages'
            }
        });
        var table = $('#table');
        table.bootstrapTable({
            url: $.fn.bootstrapTable.defaults.extend.index_url,
            pk: 'id',
            sortName: 'id',
            columns: [[
                {checkbox: true},
                {field: 'id', title: 'ID', sortable: true},
                {field: 'conversation_type', title: '类型', searchList: {1: '私聊', 2: '群聊'}, formatter: Table.api.formatter.normal},
                {field: 'conversation_id', title: '会话键', operate: 'LIKE'},
                {field: 'from_label', title: '发送方', operate: false},
                {field: 'from_user_id', title: '发送ID', sortable: true},
                {field: 'to_label', title: '接收方', operate: false},
                {field: 'to_user_id', title: '接收ID', sortable: true},
                {field: 'content', title: '内容', operate: 'LIKE', cellStyle: function () {
                    return {css: {'max-width': '280px', 'white-space': 'nowrap', 'overflow': 'hidden', 'text-overflow': 'ellipsis'}};
                }},
                {field: 'msg_type', title: '消息类型', searchList: {1: '文本', 2: '红包', 3: '系统', 4: '图片', 5: '视频', 6: '表情包', 7: '文件'}},
                {field: 'status', title: '状态', searchList: {1: '正常', 2: '撤回', 3: '删除'}, formatter: Table.api.formatter.status},
                {field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true},
                {
                    field: 'operate', title: '操作', table: table,
                    events: Table.api.events.operate,
                    formatter: Table.api.formatter.operate
                }
            ]]
        });
        Table.api.bindevent(table);

        $(document).on('click', '.btn-restore', function () {
            var ids = Table.api.selectedids(table);
            if (!ids.length) {
                Layer.msg('请选择记录');
                return false;
            }
            Backend.api.ajax({
                url: 'fanshub/imagent/restore',
                data: { ids: ids.join(',') }
            }, function () {
                table.bootstrapTable('refresh');
                return false;
            });
            return false;
        });
    }

    var Controller = {
        index: function () {
            buildEmojiPanel();
            loadConversations();
            connectWs(false);
            // 首次点击解锁浏览器音频策略
            $(document).one('click keydown touchstart', function () {
                unlockAudio();
                if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
                    try { Notification.requestPermission(); } catch (e) {}
                }
            });
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(function () {
                // WS 通时也轮询作兜底（toast 有去重）
                loadConversations({ poll: true });
            }, 4000);

            $('#btnViewChat').on('click', function () {
                $('#panelChat').addClass('show');
                $('#panelAll').removeClass('show');
                $(this).removeClass('btn-default').addClass('btn-primary');
                $('#btnViewAll').removeClass('btn-primary').addClass('btn-default');
            });
            $('#btnViewAll').on('click', function () {
                $('#panelAll').addClass('show');
                $('#panelChat').removeClass('show');
                $(this).removeClass('btn-default').addClass('btn-primary');
                $('#btnViewChat').removeClass('btn-primary').addClass('btn-default');
                initTable();
            });
            $('#btnRefreshAll').on('click', function () {
                loadConversations();
                if (current) loadHistory();
                if (tableInited) $('#table').bootstrapTable('refresh');
            });
            var searchTimer = null;
            $('#convSearch').on('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(loadConversations, 280);
            });
            $('#convList').on('click', '.im-conv-item', function () {
                openRoom({
                    conversation_type: parseInt($(this).attr('data-type'), 10) || 1,
                    conversation_id: String($(this).attr('data-cid') || ''),
                    group_id: parseInt($(this).attr('data-gid'), 10) || 0,
                    peer_a: parseInt($(this).attr('data-a'), 10) || 0,
                    peer_b: parseInt($(this).attr('data-b'), 10) || 0,
                    title: $(this).attr('data-title') || ''
                });
            });
            $('#chkIncludeDeleted').on('change', loadHistory);
            $('#btnSend').on('click', sendMsg);
            $('#msgInput').on('keydown', function (e) {
                if (e.ctrlKey && e.keyCode === 13) sendMsg();
            });
            $('#agentSelect').on('change', function () {
                if (current) loadHistory();
            });

            $('#btnEmoji').on('click', function () {
                togglePanel('#panelEmoji', this);
            });
            $('#btnSticker').on('click', function () {
                togglePanel('#panelSticker', this);
                ensureStickers();
            });
            $('#btnRedpacket').on('click', function () {
                togglePanel('#panelRedpacket', this);
            });
            $('#btnImage').on('click', function () {
                if (!current) { Layer.msg('请先选择会话'); return; }
                $('#imImageInput').click();
            });
            $('#btnVideo').on('click', function () {
                if (!current) { Layer.msg('请先选择会话'); return; }
                $('#imVideoInput').click();
            });
            $('#btnFile').on('click', function () {
                if (!current) { Layer.msg('请先选择会话'); return; }
                $('#imFileInput').click();
            });
            $('#imImageInput').on('change', function () {
                var f = this.files && this.files[0];
                this.value = '';
                uploadAndSend(f, 4);
            });
            $('#imVideoInput').on('change', function () {
                var f = this.files && this.files[0];
                this.value = '';
                uploadAndSend(f, 5);
            });
            $('#imFileInput').on('change', function () {
                var f = this.files && this.files[0];
                this.value = '';
                uploadAndSend(f, 7);
            });
            $('#panelEmoji').on('click', '.im-emoji-item', function () {
                var em = $(this).data('emoji') || $(this).text();
                var $ta = $('#msgInput');
                $ta.val(($ta.val() || '') + em).focus();
            });
            $('#panelSticker').on('click', '.im-sticker-item', function () {
                var code = String($(this).attr('data-code') || '');
                var url = String($(this).attr('data-url') || '');
                var pack = String($(this).attr('data-pack') || 'wechat');
                if (!url || !code) return;
                sendRich(6, '[' + code + ']', { url: url, fullurl: url, code: code, pack: pack });
            });
            $('#btnSendRp').on('click', function () {
                var packetType = parseInt($('#rpType').val(), 10) || 2;
                var payload = resolvePeerPayload({
                    total_amount: parseFloat($('#rpAmount').val()) || 0,
                    total_count: parseInt($('#rpCount').val(), 10) || 1,
                    packet_type: packetType,
                    mine_digit: parseInt($('#rpMineDigit').val(), 10) || 0,
                    skin_id: parseInt($('#rpSkin').val(), 10) || 0,
                    blessing: $.trim($('#rpBlessing').val() || '恭喜发财')
                });
                if (!payload) return;
                if (packetType === 3 && (payload.mine_digit < 0 || payload.mine_digit > 9)) {
                    Layer.msg('雷号须为 0-9');
                    return;
                }
                Backend.api.ajax({
                    url: 'fanshub/imagent/sendredpacket',
                    data: payload
                }, function () {
                    closePanels();
                    loadHistory();
                    loadConversations();
                    return false;
                });
            });

            var syncRpTypeUi = function () {
                var t = parseInt($('#rpType').val(), 10) || 2;
                $('#rpMineWrap').toggle(t === 3);
                var $skin = $('#rpSkin');
                $skin.find('option').each(function () {
                    var $o = $(this);
                    var pt = parseInt($o.attr('data-type') || '0', 10);
                    if ($o.val() === '0') {
                        $o.prop('disabled', false).show();
                        return;
                    }
                    var ok = (pt === 0 || pt === t);
                    $o.prop('disabled', !ok);
                    if (!ok && $o.is(':selected')) {
                        $skin.val('0');
                    }
                });
            };
            $('#rpType').on('change', syncRpTypeUi);
            syncRpTypeUi();

            $('#msgBox').on('click', '.im-media-img,.im-sticker-img', function () {
                openLightbox($(this).attr('data-preview') || $(this).attr('src'), 'image');
            });
            $('#msgBox').on('click', '.btn-edit-msg', function () {
                var id = $(this).data('id');
                Fast.api.open('fanshub/imagent/edit/ids/' + id, '编辑消息', {
                    callback: function () {
                        loadHistory();
                        loadConversations();
                    }
                });
            });
            $('#msgBox').on('click', '.btn-recall-msg', function () {
                var id = $(this).data('id');
                Layer.confirm('确认撤回该消息？', function (index) {
                    Backend.api.ajax({
                        url: 'fanshub/imagent/recall',
                        data: { id: id }
                    }, function () {
                        Layer.close(index);
                        loadHistory();
                        loadConversations();
                        return false;
                    });
                });
            });
            $('#msgBox').on('click', '.btn-del-msg', function () {
                var id = $(this).data('id');
                Layer.confirm('确认删除该消息？', function (index) {
                    Backend.api.ajax({
                        url: 'fanshub/imagent/del',
                        data: { ids: id }
                    }, function () {
                        Layer.close(index);
                        loadHistory();
                        loadConversations();
                        return false;
                    });
                });
            });
            $('#msgBox').on('click', '.btn-restore-msg', function () {
                var id = $(this).data('id');
                Backend.api.ajax({
                    url: 'fanshub/imagent/restore',
                    data: { ids: id }
                }, function () {
                    loadHistory();
                    loadConversations();
                    return false;
                });
            });
            $('#btnNewPrivate').on('click', function () {
                Layer.prompt({ title: '对方会员ID', formType: 0 }, function (value, index) {
                    var to = parseInt(value, 10) || 0;
                    var agent = parseInt($('#agentSelect').val(), 10) || 0;
                    if (to <= 0) {
                        Layer.msg('无效ID');
                        return;
                    }
                    if (!agent) {
                        Layer.msg('请先添加托管账号');
                        return;
                    }
                    Layer.close(index);
                    var a = Math.min(agent, to);
                    var b = Math.max(agent, to);
                    openRoom({
                        conversation_type: 1,
                        conversation_id: a + '_' + b,
                        group_id: 0,
                        peer_a: a,
                        peer_b: b,
                        title: '私聊 ID' + agent + ' ↔ ID' + to
                    });
                    $('#msgInput').focus();
                });
            });
        },
        add: function () {
            Form.api.bindevent($('form[role=form]'));
        },
        edit: function () {
            Form.api.bindevent($('#edit-form'));
        },
        sendprivate: function () {
            Form.api.bindevent($('#send-form'));
        },
        sendgroup: function () {
            Form.api.bindevent($('#send-form'));
        },
        messages: function () {
            Controller.index();
            $('#btnViewAll').click();
        }
    };
    return Controller;
});
