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
      path: 'graduate-verifications',
      name: 'chamber_graduate_verifications',
      meta: {
        auth: ['chamber.graduate_verification.review'],
        title: '毕业认证审核',
      },
      beforeEnter: superAdministratorOnly,
      component: () => import('@/pages/chamber/graduateVerification/index'),
    },
  ],
};
