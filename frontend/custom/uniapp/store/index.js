import Vue from 'vue'
import Vuex from 'vuex'

Vue.use(Vuex)

const store = new Vuex.Store({
  state: {
    token: '',
    userInfo: null,
    siteConfig: null
  },
  mutations: {
    setToken(state, token) {
      state.token = token
    },
    setUserInfo(state, userInfo) {
      state.userInfo = userInfo
    },
    setSiteConfig(state, config) {
      state.siteConfig = config
    }
  },
  actions: {
    // 登录成功后统一写入
    login({ commit }, { token, userInfo }) {
      commit('setToken', token)
      commit('setUserInfo', userInfo || null)
    },
    logout({ commit }) {
      commit('setToken', '')
      commit('setUserInfo', null)
    }
  }
})

export default store
