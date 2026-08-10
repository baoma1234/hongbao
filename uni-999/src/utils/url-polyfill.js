/**
 * App 端 JS 引擎常缺 URLSearchParams / URL，登录后 GET 接口会直接崩。
 * 在入口最先加载。
 */
;(function polyfillUrlApis(g) {
  if (!g) return

  if (typeof g.URLSearchParams === 'undefined') {
    function encode(v) {
      return encodeURIComponent(v == null ? '' : String(v)).replace(/%20/g, '+')
    }
    function decode(v) {
      try {
        return decodeURIComponent(String(v || '').replace(/\+/g, ' '))
      } catch (e) {
        return String(v || '')
      }
    }
    function URLSearchParamsPolyfill(init) {
      this._map = []
      if (init == null || init === '') return
      if (typeof init === 'string') {
        const s = init.charAt(0) === '?' ? init.slice(1) : init
        s.split('&').forEach((pair) => {
          if (!pair) return
          const i = pair.indexOf('=')
          const k = decode(i >= 0 ? pair.slice(0, i) : pair)
          const v = decode(i >= 0 ? pair.slice(i + 1) : '')
          this.append(k, v)
        })
        return
      }
      if (typeof init === 'object') {
        Object.keys(init).forEach((k) => {
          const val = init[k]
          if (Array.isArray(val)) val.forEach((x) => this.append(k, x))
          else if (val != null) this.append(k, val)
        })
      }
    }
    URLSearchParamsPolyfill.prototype.append = function (k, v) {
      this._map.push([String(k), String(v == null ? '' : v)])
    }
    URLSearchParamsPolyfill.prototype.set = function (k, v) {
      const key = String(k)
      this._map = this._map.filter((p) => p[0] !== key)
      this.append(key, v)
    }
    URLSearchParamsPolyfill.prototype.get = function (k) {
      const key = String(k)
      for (let i = 0; i < this._map.length; i++) {
        if (this._map[i][0] === key) return this._map[i][1]
      }
      return null
    }
    URLSearchParamsPolyfill.prototype.toString = function () {
      return this._map.map((p) => encode(p[0]) + '=' + encode(p[1])).join('&')
    }
    g.URLSearchParams = URLSearchParamsPolyfill
  }

  if (typeof g.URL === 'undefined') {
    function URLPolyfill(input, base) {
      let href = String(input || '')
      if (base && !/^([a-z][a-z0-9+.-]*:)/i.test(href)) {
        const b = String(base || '')
        if (href.charAt(0) === '/') {
          const m = b.match(/^(https?:\/\/[^/]+)/i)
          href = (m ? m[1] : b.replace(/\/+$/, '')) + href
        } else {
          href = b.replace(/\/+$/, '') + '/' + href.replace(/^\/+/, '')
        }
      }
      this.href = href
      const m = href.match(/^(https?):\/\/([^/?#]+)([^?#]*)(\?[^#]*)?(#.*)?$/i)
      if (m) {
        this.protocol = m[1].toLowerCase() + ':'
        this.host = m[2]
        this.hostname = m[2].split(':')[0]
        this.pathname = m[3] || '/'
        this.search = m[4] || ''
        this.hash = m[5] || ''
        this.origin = this.protocol + '//' + this.host
      } else {
        this.protocol = ''
        this.host = ''
        this.hostname = ''
        this.pathname = href
        this.search = ''
        this.hash = ''
        this.origin = ''
      }
    }
    URLPolyfill.prototype.toString = function () {
      return this.href
    }
    g.URL = URLPolyfill
  }
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : this)
