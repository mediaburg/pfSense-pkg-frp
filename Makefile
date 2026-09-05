# $FreeBSD$

PORTNAME=	pfSense-pkg-frp
PORTVERSION=	0.1.1
CATEGORIES=	net
MASTER_SITES=	# empty
DISTFILES=	# empty
EXTRACT_ONLY=	# empty

MAINTAINER=	info@mediaburg.ch
COMMENT=	pfSense package for FRP client
WWW=		https://github.com/fatedier/frp

LICENSE=	APACHE20

RUN_DEPENDS=	frp>=0.52.0:net/frp

NO_BUILD=	yes
# Only interpreted code and web assets; the native frpc is supplied by net/frp.
NO_ARCH=	yes
NO_MTREE=	yes

SUB_FILES=	pkg-install pkg-deinstall
SUB_LIST=	PORTNAME=${PORTNAME}

do-extract:
	${MKDIR} ${WRKSRC}

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/pkg
	${MKDIR} ${STAGEDIR}${PREFIX}/pkg/frp
	${MKDIR} ${STAGEDIR}${PREFIX}/etc/rc.d
	${MKDIR} ${STAGEDIR}${PREFIX}/www
	${MKDIR} ${STAGEDIR}${PREFIX}/www/frp-assets
	${MKDIR} ${STAGEDIR}${PREFIX}/www/widgets/widgets
	${MKDIR} ${STAGEDIR}${PREFIX}/www/widgets/include
	${MKDIR} ${STAGEDIR}${PREFIX}/libexec
	${MKDIR} ${STAGEDIR}/etc/inc/priv
	${MKDIR} ${STAGEDIR}${DATADIR}
	${INSTALL_DATA} -m 0644 ${FILESDIR}${PREFIX}/pkg/frp.xml \
		${STAGEDIR}${PREFIX}/pkg
	(cd ${FILESDIR}${PREFIX}/pkg/frp && ${COPYTREE_SHARE} . ${STAGEDIR}${PREFIX}/pkg/frp)
	(cd ${FILESDIR}${PREFIX}/www/frp-assets && ${COPYTREE_SHARE} . ${STAGEDIR}${PREFIX}/www/frp-assets)
	${INSTALL_SCRIPT} ${FILESDIR}${PREFIX}/libexec/frp-supervisor ${STAGEDIR}${PREFIX}/libexec
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/www/widgets/widgets/frp.widget.php ${STAGEDIR}${PREFIX}/www/widgets/widgets
	${INSTALL_DATA} ${FILESDIR}${PREFIX}/www/widgets/include/frp.inc ${STAGEDIR}${PREFIX}/www/widgets/include
	${INSTALL_SCRIPT} ${FILESDIR}${PREFIX}/etc/rc.d/frpc-pfsense \
		${STAGEDIR}${PREFIX}/etc/rc.d
	${INSTALL_DATA} -m 0644 ${FILESDIR}${PREFIX}/www/frp_client.php \
		${STAGEDIR}${PREFIX}/www
	${INSTALL_DATA} ${FILESDIR}/etc/inc/priv/frp.priv.inc \
		${STAGEDIR}/etc/inc/priv
	${INSTALL_DATA} ${FILESDIR}${DATADIR}/info.xml \
		${STAGEDIR}${DATADIR}
	@${REINPLACE_CMD} -i '' -e "s|%%PKGVERSION%%|${PKGVERSION}|" \
		${STAGEDIR}${PREFIX}/pkg/frp.xml \
		${STAGEDIR}${DATADIR}/info.xml

.include <bsd.port.mk>
