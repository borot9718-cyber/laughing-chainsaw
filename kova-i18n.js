/* KOVA — Traduction de l'interface (français → anglais).
   Actif uniquement si la langue choisie dans Paramètres est « English ».
   Traduit les textes fixes de l'interface ; le contenu des utilisateurs (publications,
   messages, bios, noms…) n'est JAMAIS touché. Pour ajouter une langue ou un texte : compléter ce dictionnaire. */
(function(){
"use strict";
if(window.KOVA_LANG!=="en") return;
const D={
"Accueil":"Home","Messages":"Messages","Groupes":"Groups","Communautés":"Communities","Boutique":"Shop","Notifications":"Notifications","Profil":"Profile","Paramètres":"Settings","Déconnexion":"Log out","Quitter":"Log out","Développeurs":"Developers","Administration":"Administration",
"Rechercher sur KOVA":"Search KOVA","Rechercher":"Search","Recherche":"Search","Personnes":"People","Publications":"Posts","Aucun résultat.":"No results.","Voir tous les résultats":"See all results","Saisissez au moins 2 caractères.":"Enter at least 2 characters.",
"Votre fil":"Your feed","Créer":"Create","Pour vous":"For you","Abonnements":"Following","Abonnés":"Followers","abonnés":"followers","abonnements":"following","publications":"posts","Qu'avez-vous envie de partager ?":"What's on your mind?","Photo":"Photo","Nouvelle publication":"New post","Publier":"Post","Contenu":"Content","Image (facultatif)":"Image (optional)","Origine":"Origin",
"J’aime":"Like","Commenter":"Comment","Partager":"Share","Modifier":"Edit","Supprimer":"Delete","Signaler":"Report","Envoyer":"Send","Répondre":"Reply","Annuler":"Cancel","Enregistrer":"Save","Fermer":"Close","Voir plus":"See more","Voir moins":"See less","Chargement…":"Loading…","Écrire un commentaire…":"Write a comment…","Aucun commentaire pour le moment.":"No comments yet.",
"Tout marquer lu":"Mark all as read","Aucune notification":"No notifications","Les activités importantes regroupées au même endroit.":"Your important activity in one place.","Les activités importantes apparaîtront ici.":"Important activity will show up here.",
"Modifier le profil":"Edit profile","Suivre":"Follow","Abonné(e)":"Following","Message":"Message","Bloquer":"Block","Débloquer":"Unblock","Signaler ce profil":"Report this profile","Nom affiché":"Display name","Bio":"Bio","Changer la couverture":"Change cover","Changer le mot de passe":"Change password","Mot de passe actuel":"Current password","Nouveau mot de passe":"New password","Mettre à jour":"Update","Activité":"Activity","Compte":"Account","Profil public":"Public profile","Votre profil":"Your profile","Aucune publication pour le moment.":"No posts yet.",
"Créer un groupe":"Create a group","Créer une communauté":"Create a community","Mes groupes":"My groups","Mes communautés":"My communities","Découvrir":"Discover","Demandes envoyées":"Requests sent","Rechercher un groupe":"Search groups","Rechercher une communauté":"Search communities","Rejoindre":"Join","Demander à rejoindre":"Request to join","Ouvrir":"Open","Quitter le groupe":"Leave group","Demande envoyée · Annuler":"Request sent · Cancel","Discussion":"Discussion","À propos":"About","Membres":"Members","Demandes":"Requests","Public":"Public","Privé":"Private","Confidentialité":"Privacy","Nom":"Name","Description":"Description","Approuver":"Approve","Refuser":"Decline","Exclure":"Remove","Nommer modérateur":"Make moderator","Retirer modérateur":"Remove moderator","Ce groupe est privé":"This group is private","Demandes d’adhésion":"Join requests","Aucune demande en attente.":"No pending requests.","Créateur":"Creator","Modérateur":"Moderator","Annuler ma demande":"Cancel my request",
"Nouvelle conversation":"New conversation","Conversations":"Conversations","Sélectionnez une conversation":"Select a conversation","Aucune conversation":"No conversations","Écrire un message…":"Write a message…","Message supprimé":"Message deleted","Vu":"Seen","Retrouvez vos conversations privées.":"Your private conversations.",
"Produits, vendeurs, favoris et commandes.":"Products, sellers, favorites and orders.","Vendre un produit":"Sell a product","Catalogue":"Catalog","Favoris":"Favorites","Panier":"Cart","Mes commandes":"My orders","Mes ventes":"My sales","Mes produits":"My products","Ajouter au panier":"Add to cart","Passer la commande":"Place order","Livraison":"Delivery","Téléphone":"Phone","Adresse de livraison":"Delivery address","Total":"Total","Contacter":"Contact","En attente":"Pending","Confirmée":"Confirmed","Expédiée":"Shipped","Livrée":"Delivered","Annulée":"Cancelled","Confirmer":"Confirm","Marquer expédiée":"Mark as shipped","Marquer livrée":"Mark as delivered","Rupture":"Out of stock","Toutes les catégories":"All categories","Plus récents":"Newest","Prix croissant":"Price: low to high","Prix décroissant":"Price: high to low","Votre panier est vide":"Your cart is empty","Prix":"Price","Stock":"Stock","Catégorie":"Category","Titre":"Title",
"Contrôlez votre compte, votre confidentialité et votre expérience.":"Manage your account, privacy and experience.","Langue":"Language","Langue de l’interface.":"Interface language.","Mot de passe":"Password","Applications connectées":"Connected apps","Utilisateurs bloqués":"Blocked users","Révoquer":"Revoke","Choisissez ce que vous souhaitez recevoir.":"Choose what you want to receive.","Commentaires":"Comments","Abonnés":"Followers","Se déconnecter":"Log out","Terminer votre session sur cet appareil.":"End your session on this device.",
"Connexion":"Log in","Se connecter":"Log in","Créer un compte":"Create an account","Créer mon compte":"Create my account","Adresse e-mail":"Email address","Pseudo":"Username","Date de naissance":"Date of birth","Mot de passe oublié ?":"Forgot password?","Vérifiez votre e-mail":"Verify your email","Code de vérification":"Verification code","Vérifier":"Verify","Renvoyer le code":"Resend code","Retour à la connexion":"Back to login",
"Assistance":"Support","Confidentialité ":"Privacy","Un espace social moderne.":"A modern social space.","Autoriser":"Allow","Application non vérifiée":"Unverified app","Ce n’est pas vous ?":"Not you?","Utilisateur":"User","Sécurité du compte":"Account security","Sécurité":"Security","Apparence":"Appearance","Apparence et langue":"Appearance and language","Thème":"Theme","Automatique":"Automatic","Clair":"Light","Sombre":"Dark","Confidentialité ":"Privacy","Mes données":"My data","Applications":"Apps",
"Double authentification (2FA)":"Two-factor authentication (2FA)","Double authentification":"Two-factor authentication","Activer":"Enable","Désactiver":"Disable","Activées":"Enabled","Envoyer un test":"Send a test","Tout déconnecter":"Sign out everywhere","Déconnecter tous les appareils":"Sign out all devices","Cet appareil":"This device",
"Notifications sur cet appareil":"Notifications on this device","Messages privés":"Private messages","Qui peut m’écrire ?":"Who can message me?","Tout le monde":"Everyone","Les personnes que je suis":"People I follow","Personne":"Nobody","Apparaître dans la recherche":"Appear in search",
"Exporter mes données":"Export my data","Exporter":"Export","Exercer un droit":"Exercise a right","Faire une demande":"Make a request","Supprimer mon compte":"Delete my account","Supprimer":"Delete","Mot de passe actuel":"Current password",
"Rechercher des personnes, groupes, publications, produits…":"Search people, groups, posts, products…","Tout":"All","Produits":"Products","Ne ratez plus rien":"Never miss anything","Plus tard":"Later","Vérification de sécurité en cours…":"Security check in progress…",
"Se déconnecter ?":"Log out?","Oui, me déconnecter":"Yes, log me out","Valider":"Confirm","Copier":"Copy","Télécharger":"Download","Terminer":"Done","Envoyer le signalement":"Send report","Motif":"Reason"
};
const RX=[
 [/^(\d+) membres?$/,(m,n)=>n+" member"+(n==="1"?"":"s")],
 [/^(\d+) demandes?$/,(m,n)=>n+" request"+(n==="1"?"":"s")],
 [/^À l'instant$/,()=>"Just now"],
 [/^(\d+) min$/,(m,n)=>n+" min"],
 [/^(\d+) en stock$/,(m,n)=>n+" in stock"]
];
const SKIP="script,style,textarea,input,code,pre,.user-content,[data-no-i18n]";
function tr(str){
  const t=str.trim(); if(!t) return null;
  let v=D[t];
  if(v===undefined) for(const [re,fn] of RX){const m=t.match(re); if(m){v=fn(...m);break;}}
  return v!==undefined&&v!==t?str.replace(t,v):null;
}
function text(node){
  const p=node.parentElement; if(!p||p.closest(SKIP)) return;
  const r=tr(node.nodeValue); if(r!==null) node.nodeValue=r;
}
function attrs(el){
  if(el.closest&&el.closest(".user-content")) return;
  for(const a of ["placeholder","aria-label","title"]){
    const v=el.getAttribute&&el.getAttribute(a); if(v){const r=tr(v); if(r!==null) el.setAttribute(a,r);}
  }
}
function walk(root){
  if(root.nodeType===3){text(root);return;}
  if(root.nodeType!==1) return;
  attrs(root);
  const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT|NodeFilter.SHOW_ELEMENT);
  let n; while((n=w.nextNode())){ if(n.nodeType===3) text(n); else attrs(n); }
}
walk(document.body);
document.title=tr(document.title)||document.title;
new MutationObserver(ms=>{for(const m of ms){
  if(m.type==="characterData") text(m.target);
  else m.addedNodes.forEach(walk);
}}).observe(document.body,{childList:true,subtree:true,characterData:true});
})();
