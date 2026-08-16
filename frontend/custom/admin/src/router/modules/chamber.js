import LayoutMain from '@/layout';
import setting from '@/setting';
import store from '@/store';

const routePre = setting.routePre;

function superAdministratorOnly(to, from, next) {
  const user = store.state.userInfo.userInfo;
  if (!user || Number(user.level) === 0) {
    next();
    return;
  }
  next({ name: '403' });
}

export default {
  path: routePre + '/chamber',
  name: 'chamber',
  header: 'chamber',
  redirect: {
    name: 'chamber_graduate_verifications',
  },
  meta: {
    auth: true,
  },
  component: LayoutMain,
  children: [
    {
      path: 'events',
      name: 'chamber_events',
      meta: {
        auth: ['chamber.event.manage'],
        title: '活动运营',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/events/index'),
    },
    {
      path: 'graduate-verifications',
      name: 'chamber_graduate_verifications',
      meta: {
        auth: ['chamber.graduate_verification.review'],
        title: '毕业认证审核',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/graduateVerification/index'),
    },
    {
      path: 'site-config',
      name: 'chamber_site_config',
      meta: {
        auth: ['chamber.site_config.manage'],
        title: '站点配置',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/siteConfig/index'),
    },
    {
      path: 'slots',
      name: 'chamber_slots',
      meta: {
        auth: ['chamber.slot.manage'],
        title: '大咖档期',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/slots/index'),
    },
    {
      path: 'points-paths',
      name: 'chamber_points_paths',
      meta: {
        auth: ['chamber.points_paths.manage'],
        title: '积分获取路径',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/pointsPaths/index'),
    },
    {
      path: 'products',
      name: 'chamber_products',
      meta: {
        auth: ['chamber.product.manage'],
        title: '积分商品管理',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/products/index'),
    },
    {
      path: 'expert-profile',
      name: 'chamber_expert_profile',
      meta: {
        auth: ['chamber.expert_profile.manage'],
        title: '大咖资料',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/expertProfile/index'),
    },
    {
      path: 'expert-pricing',
      name: 'chamber_expert_pricing',
      meta: {
        auth: ['chamber.expert_pricing.manage'],
        title: '大咖定价',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/expertPricing/index'),
    },
    {
      // 菜单 path=/admin/chamber/members（对齐后端菜单表，页面组件在 memberAdmin 目录）
      path: 'members',
      name: 'chamber_members',
      meta: {
        auth: ['chamber.member.manage'],
        title: '会员管理',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/memberAdmin/index'),
    },
    {
      path: 'notifications',
      name: 'chamber_notifications',
      meta: {
        auth: ['chamber.notification.manage'],
        title: '通知发布',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/notifications/index'),
    },
    {
      path: 'ai-twins',
      name: 'chamber_ai_twins',
      meta: {
        auth: ['chamber.ai_twin.manage'],
        title: 'AI分身训练',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/aiTwin/index'),
    },
  ],
};
