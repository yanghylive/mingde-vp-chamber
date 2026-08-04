import LayoutMain from '@/layout';
import setting from '@/setting';

const routePre = setting.routePre;

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
      path: 'graduate-verifications',
      name: 'chamber_graduate_verifications',
      meta: {
        auth: ['chamber.graduate_verification.review'],
        title: '毕业认证审核',
      },
      component: () => import('@/pages/chamber/graduateVerification/index'),
    },
    {
      path: 'expert-pricing',
      name: 'chamber_expert_pricing',
      meta: {
        auth: ['chamber.expert_pricing.manage'],
        title: '大咖定价',
      },
      component: () => import('@/pages/chamber/expertPricing/index'),
    },
    {
      path: 'expert-profile',
      name: 'chamber_expert_profile',
      meta: {
        auth: ['chamber.expert_profile.manage'],
        title: '大咖资料',
      },
      component: () => import('@/pages/chamber/expertProfile/index'),
    },
    {
      path: 'events',
      name: 'chamber_events',
      meta: {
        auth: ['chamber.event.manage'],
        title: '活动管理',
      },
      component: () => import('@/pages/chamber/events/index'),
    },
    {
      path: 'notifications',
      name: 'chamber_notifications',
      meta: {
        auth: ['chamber.notification.manage'],
        title: '通知发布',
      },
      component: () => import('@/pages/chamber/notifications/index'),
    },
    {
      path: 'members',
      name: 'chamber_members',
      meta: {
        auth: ['chamber.member.manage'],
        title: '会员管理',
      },
      component: () => import('@/pages/chamber/memberAdmin/index'),
    },
    {
      path: 'points-paths',
      name: 'chamber_points_paths',
      meta: {
        auth: ['chamber.points_paths.manage'],
        title: '积分路径',
      },
      component: () => import('@/pages/chamber/pointsPaths/index'),
    },
    {
      path: 'slots',
      name: 'chamber_slots',
      meta: {
        auth: ['chamber.slot.manage'],
        title: '大咖档期',
      },
      component: () => import('@/pages/chamber/slots/index'),
    },
    {
      path: 'site-config',
      name: 'chamber_site_config',
      meta: {
        auth: ['chamber.site_config.manage'],
        title: '站点配置',
      },
      component: () => import('@/pages/chamber/siteConfig/index'),
    },
  ],
};
