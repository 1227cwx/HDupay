import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from './pages/Dashboard.vue'
import RpcConfig from './pages/RpcConfig.vue'
import NetworkSettings from './pages/NetworkSettings.vue'
import ProxyPool from './pages/ProxyPool.vue'
import WalletSettings from './pages/WalletSettings.vue'
import CollectionWallets from './pages/CollectionWallets.vue'
import GasWallets from './pages/GasWallets.vue'
import Addresses from './pages/Addresses.vue'
import Orders from './pages/Orders.vue'
import Collections from './pages/Collections.vue'
import Withdrawals from './pages/Withdrawals.vue'
import WithdrawSettings from './pages/WithdrawSettings.vue'
import DepositCreate from './pages/DepositCreate.vue'
import OpenApi from './pages/OpenApi.vue'
import Pay from './pages/Pay.vue'
import Login from './pages/Login.vue'
import AdminProfile from './pages/AdminProfile.vue'
import FiatRates from './pages/FiatRates.vue'

const admin = '/hdupay'

export default createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/pay' },
    { path: '/pay', component: Pay, meta: { public: true } },
    { path: '/login', redirect: '/pay' },
    { path: admin, redirect: `${admin}/login` },
    { path: `${admin}/login`, component: Login, meta: { public: true } },
    { path: `${admin}/overview`, component: Dashboard },
    { path: `${admin}/dashboard`, redirect: `${admin}/overview` },
    { path: `${admin}/rpc`, component: RpcConfig },
    { path: `${admin}/network-settings`, component: NetworkSettings },
    { path: `${admin}/proxies`, component: ProxyPool },
    { path: `${admin}/wallet-settings`, component: WalletSettings },
    { path: `${admin}/wallet`, redirect: `${admin}/wallet-settings` },
    { path: `${admin}/collection-wallets`, component: CollectionWallets },
    { path: `${admin}/gas-wallets`, component: GasWallets },
    { path: `${admin}/addresses`, component: Addresses },
    { path: `${admin}/orders`, component: Orders },
    { path: `${admin}/transactions`, redirect: `${admin}/orders` },
    { path: `${admin}/collections`, component: Collections },
    { path: `${admin}/withdraw-settings`, component: WithdrawSettings },
    { path: `${admin}/withdrawals`, component: Withdrawals },
    { path: `${admin}/deposit-create`, component: DepositCreate },
    { path: `${admin}/open-api`, component: OpenApi },
    { path: `${admin}/admin-profile`, component: AdminProfile },
    { path: `${admin}/fiat-rates`, component: FiatRates },
    { path: `${admin}/admin-settings`, redirect: `${admin}/admin-profile` }
  ]
})
