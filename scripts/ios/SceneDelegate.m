#import "AppDelegate.h"
#import "PDRCore.h"

@interface AppDelegate (YuzhouScene)
- (void)connectWindowScene:(UIWindowScene *)scene;
@end

@interface SceneDelegate : UIResponder <UIWindowSceneDelegate>
@property (strong, nonatomic) UIWindow *window;
@end

@implementation SceneDelegate
- (void)scene:(UIScene *)scene willConnectToSession:(UISceneSession *)session
      options:(UISceneConnectionOptions *)options {
    if (![scene isKindOfClass:[UIWindowScene class]]) return;
    AppDelegate *delegate = (AppDelegate *)UIApplication.sharedApplication.delegate;
    [delegate connectWindowScene:(UIWindowScene *)scene];
    self.window = delegate.window;
    [self scene:scene openURLContexts:options.URLContexts];
    for (NSUserActivity *activity in options.userActivities) {
        [self scene:scene continueUserActivity:activity];
    }
    if (options.shortcutItem) {
        [PDRCore handleSysEvent:PDRCoreSysEventPeekQuickAction withObject:options.shortcutItem];
    }
}

- (void)sceneDidBecomeActive:(UIScene *)scene {
    [PDRCore handleSysEvent:PDRCoreSysEventBecomeActive withObject:nil];
}
- (void)sceneWillResignActive:(UIScene *)scene {
    [PDRCore handleSysEvent:PDRCoreSysEventResignActive withObject:nil];
}
- (void)sceneDidEnterBackground:(UIScene *)scene {
    [PDRCore handleSysEvent:PDRCoreSysEventEnterBackground withObject:nil];
}
- (void)sceneWillEnterForeground:(UIScene *)scene {
    [PDRCore handleSysEvent:PDRCoreSysEventEnterForeGround withObject:nil];
}
- (void)scene:(UIScene *)scene openURLContexts:(NSSet<UIOpenURLContext *> *)contexts {
    for (UIOpenURLContext *context in contexts) {
        NSMutableDictionary *options = [NSMutableDictionary dictionary];
        if (context.options.sourceApplication) {
            options[UIApplicationOpenURLOptionsSourceApplicationKey] = context.options.sourceApplication;
        }
        if (context.options.annotation) {
            options[UIApplicationOpenURLOptionsAnnotationKey] = context.options.annotation;
        }
        options[UIApplicationOpenURLOptionsOpenInPlaceKey] = @(context.options.openInPlace);
        [PDRCore handleSysEvent:PDRCoreSysEventOpenURLWithOptions withObject:@[context.URL, options]];
    }
}
- (void)scene:(UIScene *)scene continueUserActivity:(NSUserActivity *)activity {
    [PDRCore handleSysEvent:PDRCoreSysEventContinueUserActivity withObject:activity];
}
- (void)windowScene:(UIWindowScene *)scene performActionForShortcutItem:(UIApplicationShortcutItem *)item
 completionHandler:(void (^)(BOOL))completionHandler {
    [PDRCore handleSysEvent:PDRCoreSysEventPeekQuickAction withObject:item];
    completionHandler(YES);
}
@end
