import Storage from "./Storage";
import { IJID } from "jsxc/src/JID.interface";

interface IAvatar {
   username: string,
   type: 'url' | 'placeholder',
   displayName?: string,
   url?: string;
}

export default async function defaultAvatar(elements: JQuery, name: string, jid: IJID) {
   let storage = Storage.get();

   let defaultDomain = storage.getItem('defaultDomain');
   let isExternalUser = jid.domain !== defaultDomain;
   let avatar: IAvatar = {
      username: jid.node,
      displayName: name,
      type: 'placeholder',
   };

   if (!isExternalUser) {
      let maxSize = elements.get().reduce((currentMax, element) => {
         if ($(element).width() > currentMax) {
            currentMax = $(element).width();
         }
         if ($(element).height() > currentMax) {
            currentMax = $(element).height();
         }
         return currentMax;
      }, 0);

      avatar = await getAvatar(jid, maxSize);
   }

   $(elements).each(function () {
      let element = $(this);

      if (element.length === 0) {
         return;
      }

      displayAvatar(element, avatar);
   });
}

async function getAvatar(jid: IJID, size: number): Promise<IAvatar> {
   let username = jid.node;
   let key = username + '@' + size;
   let cache = Storage.get().getItem('avatar:' + key);

   if (cache) {
      return cache;
   }

   let avatar = await requestAvatar(username, size);

   Storage.get().setItem('avatar:' + key, avatar);

   return avatar;
}

function requestAvatar(username: string, size: number): Promise<IAvatar> {
   let url = getAvatarUrl(username, size);

   return new Promise(resolve => {
      $.get(url, function (result) {
         resolve({
            username: username,
            type: typeof result === 'string' ? 'url' : 'placeholder',
            displayName: result.data && result.data.displayname ? result.data.displayname : undefined,
            url: typeof result === 'string' ? result : undefined,
         });
      }).fail(() => {
         resolve({
            username,
            type: 'placeholder',
         });
      });
   });
}

function displayAvatar(element: JQuery, avatar: IAvatar) {
   if (avatar && avatar.type === 'url') {
      element.css('backgroundImage', 'url(' + avatar.url + ')');
      element.text('');
   } else {
      setPlaceholder(element, avatar.username, avatar.displayName);
   }
}

function getAvatarUrl(username: string, size: number) {
   return OC.generateUrl('/avatar/' + encodeURIComponent(username) + '/' + size + '?requesttoken={requesttoken}', {
      user: username,
      size: size,
      requesttoken: oc_requesttoken
   })
}

function setPlaceholder(element, username: string, displayName?: string) {
   (<any>element).imageplaceholder(username, displayName);
}